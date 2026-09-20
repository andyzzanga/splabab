<?php
// Standalone /inf application; compatible with the host's PHP 5.2 runtime.
define('INF_DATA', '/var/lib/labab-inf');
date_default_timezone_set('Asia/Seoul');
ini_set('display_errors', '0');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
ini_set('session.gc_maxlifetime', '7200');
session_save_path(INF_DATA . '/sessions');
$portal=isset($_GET['portal'])?$_GET['portal']:'influencer';
if(!in_array($portal,array('admin','client','influencer'))) $portal='influencer';
define('INF_PORTAL',$portal);
$restaurant=isset($_GET['restaurant']) && is_string($_GET['restaurant'])?$_GET['restaurant']:'';
$restaurantKeys=array('labab'=>1,'eodiya'=>2,'jangdak'=>3,'pallyonggak'=>4);
$restaurantId=isset($restaurantKeys[$restaurant])?$restaurantKeys[$restaurant]:(ctype_digit($restaurant)?intval($restaurant):0);
if($restaurant && (!$restaurantId || $portal!=='client')){header('HTTP/1.1 404 Not Found');exit;}
define('INF_CLIENT_ID',$restaurantId);
function client_portal_url($id) { $keys=array(1=>'labab',2=>'eodiya',3=>'jangdak',4=>'pallyonggak');return 'https://splabab.co.kr/inf/client/'.(isset($keys[$id])?$keys[$id].'/':'?restaurant='.intval($id)); }

session_name(($portal==='influencer'?'LABABINF':'LABABINF_'.strtoupper($portal)).(INF_CLIENT_ID?'_'.INF_CLIENT_ID:''));
session_set_cookie_params(0, '/inf/', '', true, true);
session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' blob:; style-src 'self'; script-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
function demo_mode() { return !empty($_SESSION['demo']) || (empty($_SESSION['uid']) && isset($_GET['demo']) && $_GET['demo']==='1'); }
function db() {
    static $dbs = array();
    $mode=isset($GLOBALS['inf_demo_override'])?$GLOBALS['inf_demo_override']:demo_mode();
    $file=$mode?'demo.sqlite':'app.sqlite';
    if (!isset($dbs[$file])) {
        $db = new PDO('sqlite:' . INF_DATA . '/'.$file);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA busy_timeout=5000');
        $dbs[$file]=$db;
    }
    return $dbs[$file];
}
function query($sql, $args = array()) { $q = db()->prepare($sql); $q->execute($args); return $q; }
function one($sql, $args = array()) { return query($sql, $args)->fetch(PDO::FETCH_ASSOC); }
function rows($sql, $args = array()) { return query($sql, $args)->fetchAll(PDO::FETCH_ASSOC); }
function random_hex($size) {
    $f = fopen('/dev/urandom', 'rb'); $s = fread($f, $size); fclose($f);
    if (strlen($s) != $size) throw new Exception('Random source unavailable');
    return bin2hex($s);
}
function equal_safe($a, $b) {
    if (!is_string($a) || !is_string($b) || strlen($a) !== strlen($b)) return false;
    $x = 0; for ($i=0; $i<strlen($a); $i++) $x |= ord($a[$i]) ^ ord($b[$i]);
    return $x === 0;
}
// PBKDF2-HMAC-SHA512, 210,000 iterations, 32-byte derived key.
function derive_password($password, $salt) {
    $u = hash_hmac('sha512', $salt . pack('N', 1), $password, true); $out = $u;
    for ($i=1; $i<210000; $i++) { $u=hash_hmac('sha512', $u, $password, true); $out=$out ^ $u; }
    return bin2hex(substr($out, 0, 32));
}
function password_make($password) { $salt=random_hex(16); return $salt . ':' . derive_password($password, $salt); }
function password_check($password, $stored) { $p=explode(':',$stored); return count($p)===2 && equal_safe($p[1],derive_password($password,$p[0])); }
function answer($data, $status=200) { if(session_id()) header('Set-Cookie: '.session_name().'='.session_id().'; Path=/inf/; Secure; HttpOnly; SameSite=Lax',false); header('Content-Type: application/json; charset=utf-8'); header('HTTP/1.1 ' . $status); echo json_encode($data); exit; }
function fail($code, $status=400) { answer(array('error'=>$code),$status); }
function input($key, $max=2000) { $v=isset($_POST[$key])?trim($_POST[$key]):''; if (!is_string($v) || strlen($v)>$max) fail('invalid_input'); return $v; }
function auth() {
    if (empty($_SESSION['uid'])) return false;
    if (empty($_SESSION['seen']) || $_SESSION['seen'] < time()-7200) { $_SESSION=array(); return false; }
    $_SESSION['seen']=time();
    $u=one('SELECT id,email,name,instagram,role,client_id,approval_status,approval_note,is_blacklisted FROM users WHERE id=?',array($_SESSION['uid']));
    if(INF_CLIENT_ID && (!$u || $u['role']!=='client' || intval($u['client_id'])!==INF_CLIENT_ID))return false;
    return $u;
}
function need_user() { $u=auth(); if (!$u) fail('login_required',401); return $u; }
function need_admin() { $u=need_user(); if ($u['role']!=='admin') fail('forbidden',403); return $u; }
function manage_campaign($u,$id) { $c=campaign($id); if(!$c || !($u['role']==='admin' || ($u['role']==='client' && $u['client_id']===$c['client_id']))) fail('forbidden',403); return $c; }
function csrf() { if(empty($_SESSION['csrf'])) $_SESSION['csrf']=random_hex(24); return $_SESSION['csrf']; }
function check_csrf() { if (!equal_safe(csrf(),input('csrf',128))) fail('session_expired',403); }
function begin_write() { db()->exec('BEGIN IMMEDIATE'); }
function commit_write() { db()->exec('COMMIT'); }
function rollback_write() { try {db()->exec('ROLLBACK');} catch(Exception $e) {} }
function throttle($kind, $limit) {
    $key=hash('sha256',$kind . '|' . $_SERVER['REMOTE_ADDR']); $cut=time()-900;
    query('DELETE FROM attempts WHERE created_at < ?',array($cut));
    $r=one('SELECT COUNT(*) AS n FROM attempts WHERE key_hash=?',array($key));
    if ($r['n'] >= $limit) fail('rate_limit',429);
    query('INSERT INTO attempts(key_hash,created_at) VALUES(?,?)',array($key,time()));
}
function campaign($id) { return one('SELECT * FROM campaigns WHERE id=?',array($id)); }
function may_view($u,$b) { return $u['role']==='admin' || ($u['role']==='influencer' && $b['user_id']===$u['id']) || ($u['role']==='client' && $b['client_id']===$u['client_id']); }
function booking_detail($id) { return one('SELECT b.*,c.client_id FROM bookings b JOIN campaigns c ON c.id=b.campaign_id WHERE b.id=?',array($id)); }
function audit($actor,$event,$entity) { query('INSERT INTO audit(actor_id,event,entity_id,created_at) VALUES(?,?,?,?)',array($actor,$event,$entity,date('c'))); }

function normalized_tags($text) {
    $parts=preg_split('/[\s,]+/u',trim($text),-1,PREG_SPLIT_NO_EMPTY);$tags=array();
    foreach($parts as $tag){if(!preg_match('/^#[\p{L}\p{N}_]+$/u',$tag))fail('tags_invalid');if(!in_array($tag,$tags))$tags[]=$tag;}
    if(count($tags)>30)fail('tags_invalid');return implode(' ',$tags);
}
function missing_tags($caption,$required) {
    $matches=array();preg_match_all('/(?<![\p{L}\p{N}_])#[\p{L}\p{N}_]+/u',$caption,$matches);$found=array_map('strtolower',$matches[0]);$missing=array();
    foreach(explode(' ',trim($required)) as $tag)if($tag!=='' && !in_array(strtolower($tag),$found))$missing[]=$tag;return $missing;
}
