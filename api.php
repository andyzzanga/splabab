<?php
require dirname(__FILE__) . '/lib.php';
function campaign_client_id($id){$c=campaign($id);return $c['client_id'];}
try {
    $action=isset($_GET['action'])?$_GET['action']:'state';
    if ($action==='state') {
        $u=auth(); $c=campaign(1); $invite=null;
        $token=isset($_POST['invite'])?$_POST['invite']:(isset($_GET['invite'])?$_GET['invite']:'');
        if ($token && is_string($token) && strlen($token)<=100) {
            $i=one('SELECT role,campaign_id,label FROM invites WHERE token_hash=? AND used_by IS NULL AND expires_at>?',array(hash('sha256',$token),time()));
            if($i) { $invite=$i; $c=campaign($i['campaign_id']); }
        }
        $out=array('user'=>$u,'demo'=>demo_mode(),'csrf'=>csrf(),'campaign'=>$c,'invite'=>$invite,'slots'=>array(),'bookings'=>array(),'campaigns'=>array());
        $out['campaigns']=rows('SELECT * FROM campaigns WHERE listed=1 ORDER BY id');
        $out['client_portal']=null;
        if(INF_CLIENT_ID){
            $cl=one('SELECT id,name FROM clients WHERE id=?',array(INF_CLIENT_ID));if(!$cl)fail('not_found',404);
            $cl['url']=client_portal_url($cl['id']);$out['client_portal']=$cl;
            $out['campaigns']=rows('SELECT * FROM campaigns WHERE client_id=? AND listed=1 ORDER BY id',array(INF_CLIENT_ID));
            $out['campaign']=count($out['campaigns'])?$out['campaigns'][0]:null;
        }
        if ($u) {
            if($u['role']==='admin') {
                $out['campaigns']=rows('SELECT c.*,cl.name AS client_name FROM campaigns c JOIN clients cl ON cl.id=c.client_id');
                $out['clients']=rows("SELECT cl.id,cl.name,(SELECT COUNT(*) FROM campaigns c WHERE c.client_id=cl.id) AS campaign_count,(SELECT COUNT(*) FROM campaigns c WHERE c.client_id=cl.id AND c.active=1) AS active_count,(SELECT COUNT(*) FROM users u WHERE u.client_id=cl.id AND u.role='client') AS account_count,(SELECT COUNT(*) FROM bookings b JOIN campaigns c ON c.id=b.campaign_id WHERE c.client_id=cl.id AND b.status<>'cancelled') AS booking_count FROM clients cl ORDER BY cl.id");foreach($out['clients'] as $k=>$cl)$out['clients'][$k]['url']=client_portal_url($cl['id']);
                $out['creators']=rows("SELECT id,email,name,instagram,approval_status,approval_note,is_blacklisted,created_at FROM users WHERE role='influencer' ORDER BY id DESC");
                $out['blacklist_events']=rows("SELECT e.*,a.name AS actor_name FROM blacklist_events e LEFT JOIN users a ON a.id=e.actor_id ORDER BY e.id DESC");
                $out['invites']=rows('SELECT id,label,role,campaign_id,used_by,expires_at FROM invites ORDER BY id DESC LIMIT 50');
                $out['bookings']=rows('SELECT b.*,s.starts_at,u.name,u.instagram,u.email,c.title_en,c.title_ko,c.branch_en,c.branch_ko FROM bookings b JOIN users u ON b.user_id=u.id JOIN slots s ON s.id=b.slot_id JOIN campaigns c ON c.id=b.campaign_id ORDER BY b.id DESC LIMIT 500');
            } elseif($u['role']==='client') {
                $out['campaigns']=rows('SELECT * FROM campaigns WHERE client_id=?',array($u['client_id']));
                $cl=one('SELECT id,name FROM clients WHERE id=?',array($u['client_id']));$cl['url']=client_portal_url($cl['id']);$out['client_portal']=$cl;
                // Clients never receive email, receipt paths, payment references or payout account information.
                $out['bookings']=rows('SELECT b.id,b.campaign_id,b.status,b.post_url,b.created_at,b.meal_mode,b.offer_type,b.menu_en,b.menu_ko,b.checked_in_at,s.starts_at,u.name,u.instagram,c.title_en,c.title_ko,c.branch_en,c.branch_ko FROM bookings b JOIN users u ON b.user_id=u.id JOIN slots s ON s.id=b.slot_id JOIN campaigns c ON c.id=b.campaign_id WHERE c.client_id=? ORDER BY b.id DESC LIMIT 500',array($u['client_id']));
            } else {
                $out['campaigns']=rows('SELECT * FROM campaigns WHERE listed=1 ORDER BY id');
                $out['bookings']=rows('SELECT b.*,s.starts_at,c.title_en,c.title_ko,c.branch_en,c.branch_ko FROM bookings b JOIN slots s ON s.id=b.slot_id JOIN campaigns c ON c.id=b.campaign_id WHERE b.user_id=? ORDER BY b.id DESC',array($u['id']));
            }
            if(count($out['campaigns'])) $out['campaign']=$out['campaigns'][0];
            foreach($out['campaigns'] as $cc) {
                $ss=rows("SELECT s.*, (SELECT COUNT(*) FROM bookings b WHERE b.slot_id=s.id AND b.status<>'cancelled') AS booked FROM slots s WHERE s.campaign_id=? AND s.starts_at>? ORDER BY s.starts_at",array($cc['id'],date('Y-m-d H:i:s')));
                $out['slots']=array_merge($out['slots'],$ss);
            }
        }
        if(($u && $u['role']==='client') || (!$u && INF_PORTAL==='client')){
            foreach($out['campaigns'] as $k=>$cc){unset($out['campaigns'][$k]['cap']);unset($out['campaigns'][$k]['reward']);}
            if($out['campaign']){unset($out['campaign']['cap']);unset($out['campaign']['reward']);}
        }
        if($u && $u['role']==='influencer')foreach($out['bookings'] as $k=>$b){$bc=campaign($b['campaign_id']);$out['bookings'][$k]['checkin_url']=client_portal_url($bc['client_id']).'#'.(demo_mode()?'demo=1&':'').'checkin='.$b['checkin_token'];}
        answer($out);
    }
    if ($action==='receipt') {
        $u=need_user(); $b=booking_detail(isset($_GET['id'])?intval($_GET['id']):0);
        // Receipts contain payment information: operator and submitting influencer only.
        if(!$b || !($u['role']==='admin' || ($u['role']==='influencer' && $b['user_id']===$u['id'])) || !$b['receipt_path']) fail('forbidden',403);
        header('Content-Type: '.$b['receipt_type']); header('Content-Disposition: inline; filename="receipt"');
        readfile(INF_DATA.'/uploads/'.$b['receipt_path']); exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='POST') fail('method_not_allowed',405);
    check_csrf();
    if($action==='register') {
        throttle('register',8); $email=strtolower(input('email',180)); $pw=input('password',128); $name=input('name',100); $ig=ltrim(input('instagram',32),'@');
        if(!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($pw)<12 || !$name) fail('signup_invalid');
        $token=input('invite',100); $setup=input('setup',100);
        $adminCount=one("SELECT COUNT(*) AS n FROM users WHERE role='admin'");
        $setupFile=INF_DATA.'/setup-token';
        $isSetup=$setup && !$adminCount['n'] && file_exists($setupFile) && equal_safe(trim(file_get_contents($setupFile)),hash('sha256',$setup));
        $publicSignup=!$token && !$setup && INF_PORTAL==='influencer';
        $i=$publicSignup?array('role'=>'influencer','campaign_id'=>1):($isSetup?array('role'=>'admin','campaign_id'=>1):one('SELECT * FROM invites WHERE token_hash=? AND used_by IS NULL AND expires_at>?',array(hash('sha256',$token),time())));
        if(!$i) fail('invite_invalid');
        if(INF_CLIENT_ID){$ic=campaign($i['campaign_id']);if($i['role']!=='client'||intval($ic['client_id'])!==INF_CLIENT_ID)fail('client_mismatch',403);}
        if($i['role']==='influencer' && !preg_match('/^[A-Za-z0-9._]{1,30}$/',$ig)) fail('instagram_invalid');
        if(one('SELECT id FROM users WHERE email=?',array($email))) fail('email_used');
        $hashed=password_make($pw); $c=campaign($i['campaign_id']);
        begin_write();
        if($isSetup) { $n=one("SELECT COUNT(*) AS n FROM users WHERE role='admin'"); if($n['n']) {rollback_write();fail('invite_invalid');} }
        elseif(!$publicSignup) { $i=one('SELECT * FROM invites WHERE token_hash=? AND used_by IS NULL AND expires_at>?',array(hash('sha256',$token),time())); if(!$i){rollback_write();fail('invite_invalid');} }
        query('INSERT INTO users(email,password,name,instagram,role,client_id,created_at) VALUES(?,?,?,?,?,?,?)',array($email,$hashed,$name,$ig,$i['role'],$i['role']==='client'?$c['client_id']:null,date('c')));
        $uid=db()->lastInsertId();
        if(!$isSetup && !$publicSignup) query('UPDATE invites SET used_by=? WHERE id=?',array($uid,$i['id']));
        if($i['role']==='influencer') {
            query('UPDATE users SET approval_status=? WHERE id=?',array('pending',$uid));
            if(!$publicSignup)query('INSERT INTO memberships(user_id,campaign_id) VALUES(?,?)',array($uid,$c['id']));
        }
        commit_write(); if($isSetup) unlink($setupFile);
        $usingDemo=demo_mode();session_regenerate_id(true); $_SESSION['uid']=$uid; $_SESSION['seen']=time(); $_SESSION['csrf']=random_hex(24);$_SESSION['demo']=$usingDemo;
        answer(array('ok'=>true));
    }
    if($action==='login') {
        $email=strtolower(input('email',180)); $pw=input('password',128);
        $isDemo=$email==='admin' || $email==='4285';
        $GLOBALS['inf_demo_override']=$isDemo;
        if($isDemo)$email='demo-'.INF_PORTAL.(INF_PORTAL==='client' && INF_CLIENT_ID>1?'-'.INF_CLIENT_ID:'').'@example.invalid';
        $key=hash('sha256','login-failure:'.$email.'|'.$_SERVER['REMOTE_ADDR']);
        query('DELETE FROM attempts WHERE created_at < ?',array(time()-900));
        $failed=one('SELECT COUNT(*) AS n FROM attempts WHERE key_hash=?',array($key));
        if(!$isDemo && $failed['n']>=15) fail('rate_limit',429);
        $u=one('SELECT * FROM users WHERE email=?',array($email));
        if(!$u || !password_check($pw,$u['password'])) {
            if($failed['n']<15)query('INSERT INTO attempts(key_hash,created_at) VALUES(?,?)',array($key,time()));
            if($failed['n']>=14)fail('rate_limit',429);
            fail('login_invalid',401);
        }
        if(INF_CLIENT_ID && ($u['role']!=='client'||intval($u['client_id'])!==INF_CLIENT_ID))fail('client_mismatch',403);
        query('DELETE FROM attempts WHERE key_hash=? OR key_hash=?',array($key,hash('sha256','login|'.$_SERVER['REMOTE_ADDR'])));
        session_regenerate_id(true); $_SESSION=array('uid'=>$u['id'],'seen'=>time(),'csrf'=>random_hex(24),'demo'=>$isDemo); answer(array('ok'=>true));
    }
    $u=need_user();
    if($action==='logout') { $_SESSION=array(); session_destroy(); answer(array('ok'=>true)); }
    if($action==='checkin_preview' || $action==='checkin_confirm') {
        if(!in_array($u['role'],array('admin','client')))fail('forbidden',403);
        $token=input('token',48);if(!preg_match('/^[a-f0-9]{48}$/',$token))fail('qr_invalid',404);
        if($action==='checkin_confirm')begin_write();
        $b=one('SELECT b.id,b.status,b.checked_in_at,b.meal_mode,b.offer_type,b.menu_en,b.menu_ko,b.cap,s.starts_at,c.client_id,c.title_en,c.title_ko,c.branch_en,c.branch_ko,u.name,u.instagram FROM bookings b JOIN slots s ON s.id=b.slot_id JOIN campaigns c ON c.id=b.campaign_id JOIN users u ON u.id=b.user_id WHERE b.checkin_token=?',array($token));
        if(!$b || ($u['role']==='client' && intval($b['client_id'])!==intval($u['client_id']))){rollback_write();fail('qr_invalid',404);}
        if($b['status']==='cancelled'){rollback_write();fail('qr_cancelled',409);}
        $b['can_checkin']=!$b['checked_in_at'] && substr($b['starts_at'],0,10)===date('Y-m-d') && $b['status']==='reserved';
        if($action==='checkin_confirm'){
            if($b['checked_in_at']){rollback_write();fail('already_checked_in',409);}
            if(!$b['can_checkin']){rollback_write();fail('checkin_day',409);}
            $now=date('c');$q=query('UPDATE bookings SET checked_in_at=?,checked_in_by=? WHERE id=? AND checked_in_at IS NULL',array($now,$u['id'],$b['id']));
            if(!$q->rowCount()){rollback_write();fail('already_checked_in',409);}audit($u['id'],'checkin',$b['id']);commit_write();$b['checked_in_at']=$now;$b['can_checkin']=false;
        }
        if($u['role']==='client')unset($b['cap']);
        answer(array('ok'=>true,'booking'=>$b));
    }
    if($action==='claim') {
        if($u['role']!=='influencer') fail('forbidden',403);
        begin_write(); $i=one("SELECT * FROM invites WHERE token_hash=? AND used_by IS NULL AND expires_at>? AND role='influencer'",array(hash('sha256',input('invite',100)),time()));
        if(!$i){rollback_write();fail('invite_invalid');}
        query('INSERT OR IGNORE INTO memberships(user_id,campaign_id) VALUES(?,?)',array($u['id'],$i['campaign_id']));
        query('UPDATE invites SET used_by=? WHERE id=?',array($u['id'],$i['id'])); commit_write(); answer(array('ok'=>true));
    }
    if($action==='book') {
        if($u['role']!=='influencer') fail('forbidden',403);
        begin_write();$fresh=one('SELECT approval_status,is_blacklisted FROM users WHERE id=?',array($u['id']));
        if($fresh && intval($fresh['is_blacklisted'])){rollback_write();fail('account_restricted',403);}
        if(!$fresh || $fresh['approval_status']!=='approved'){rollback_write();fail('approval_required',403);}
        $s=one('SELECT s.*,c.cap,c.reward,c.active,c.required_tags,c.enforce_tags,c.guide_en,c.guide_ko,c.meal_mode,c.offer_type,c.menu_en,c.menu_ko FROM slots s JOIN campaigns c ON c.id=s.campaign_id WHERE s.id=? AND c.listed=1',array(intval(input('slot'))));
        if(!$s || !$s['active'] || $s['starts_at']<=date('Y-m-d H:i:s')) {rollback_write();fail('slot_unavailable');}
        $existing=one("SELECT id FROM bookings WHERE user_id=? AND campaign_id=? AND status<>'cancelled'",array($u['id'],$s['campaign_id']));
        $count=one("SELECT COUNT(*) AS n FROM bookings WHERE slot_id=? AND status<>'cancelled'",array($s['id']));
        if($existing || $count['n'] >= $s['capacity']) {rollback_write();fail($existing?'already_booked':'slot_full');}
        query('INSERT INTO bookings(user_id,campaign_id,slot_id,status,cap,reward,created_at,required_tags,enforce_tags,guide_en,guide_ko,checkin_token,meal_mode,offer_type,menu_en,menu_ko) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',array($u['id'],$s['campaign_id'],$s['id'],'reserved',$s['cap'],$s['reward'],date('c'),$s['required_tags'],$s['enforce_tags'],$s['guide_en'],$s['guide_ko'],random_hex(24),$s['meal_mode'],$s['offer_type'],$s['menu_en'],$s['menu_ko']));
        audit($u['id'],'book',db()->lastInsertId()); commit_write(); answer(array('ok'=>true));
    }
    if($action==='cancel') {
        $b=one('SELECT b.*,s.starts_at FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE b.id=?',array(intval(input('id'))));
        if(!$b || $b['user_id']!==$u['id'] || $b['status']!=='reserved' || $b['checked_in_at'] || $b['starts_at']<=date('Y-m-d H:i:s')) fail('invalid_status');
        $q=query("UPDATE bookings SET status='cancelled' WHERE id=? AND status='reserved' AND checked_in_at IS NULL",array($b['id'])); if(!$q->rowCount())fail('invalid_status'); audit($u['id'],'cancel',$b['id']); answer(array('ok'=>true));
    }
    if($action==='submit') {
        $b=booking_detail(intval(input('id')));
        if(!$b || $u['role']!=='influencer' || $b['user_id']!==$u['id'] || !in_array($b['status'],array('reserved','changes'))) fail('invalid_status');
        $s=one('SELECT starts_at FROM slots WHERE id=?',array($b['slot_id'])); if($s['starts_at']>date('Y-m-d H:i:s') && !$b['checked_in_at']) fail('visit_first');
        $provided=$b['meal_mode']==='provided';if($provided && !$b['checked_in_at'])fail('meal_checkin_required');
        $a=$provided?'0':input('amount',10); $url=input('post_url',600);
        if(!$provided && (!ctype_digit($a) || intval($a)<1 || intval($a)>10000000)) fail('amount_invalid');
        if(!preg_match('~^https://(www\.)?instagram\.com/(p|reel|reels)/[A-Za-z0-9_-]+/?(?:\?[^\s]*)?$~',$url)) fail('post_invalid');
        $caption=input('post_caption',10000);
        if(!$caption)fail('caption_required');if(input('guide_confirmed',1)!=='1')fail('guide_required');
        if(intval($b['enforce_tags']) && count(missing_tags($caption,$b['required_tags'])))fail('tags_missing');
        $path=$b['receipt_path']; $type=$b['receipt_type']; $new=false;
        if(!$provided && isset($_FILES['receipt']) && $_FILES['receipt']['error']!==UPLOAD_ERR_NO_FILE) {
            $f=$_FILES['receipt']; if($f['error']!==UPLOAD_ERR_OK || $f['size']>8*1024*1024 || !is_uploaded_file($f['tmp_name'])) fail('receipt_invalid');
            $info=@getimagesize($f['tmp_name']);
            if(!$info || !in_array($info[2],array(IMAGETYPE_JPEG,IMAGETYPE_PNG)) || $info[0]*$info[1]>40000000) fail('receipt_invalid');
            $path=random_hex(24); $type=$info[2]===IMAGETYPE_JPEG?'image/jpeg':'image/png';
            if(!move_uploaded_file($f['tmp_name'],INF_DATA.'/uploads/'.$path)) fail('storage_unavailable',503);
            chmod(INF_DATA.'/uploads/'.$path,0600); $new=true;
        }
        if(!$provided && !$path) fail('receipt_required');
        $q=query("UPDATE bookings SET status='submitted',amount=?,receipt_path=?,receipt_type=?,post_url=?,submitted_at=?,post_caption=?,guide_confirmed=1,content_checked=0 WHERE id=? AND user_id=? AND status IN ('reserved','changes')",array(intval($a),$path,$type,$url,date('c'),$caption,$b['id'],$u['id']));
        if(!$q->rowCount()){if($new)unlink(INF_DATA.'/uploads/'.$path);fail('invalid_status');}
        if($new && $b['receipt_path']) @unlink(INF_DATA.'/uploads/'.$b['receipt_path']);
        audit($u['id'],'submit',$b['id']); answer(array('ok'=>true));
    }
    if($action==='campaign') {
        $id=intval(input('id')); $existing=manage_campaign($u,$id); $en=input('title_en',120);$ko=input('title_ko',180);$cap=$u['role']==='admin'?input('cap',8):strval($existing['cap']);$reward=$u['role']==='admin'?input('reward',8):strval($existing['reward']);
        if(!$en||!$ko||!ctype_digit($cap)||!ctype_digit($reward)||intval($cap)>10000000||intval($reward)>10000000)fail('invalid_input');
        $ben=input('branch_en',200);$bko=input('branch_ko',200);$aen=input('address_en',500);$ako=input('address_ko',500);$active=input('active')==='1'?1:0;
        if($active && (!$ben||!$bko||!$aen||!$ako)) fail('location_required');
        if(!campaign($id))fail('not_found',404);
        $meal=isset($_POST['meal_mode'])?input('meal_mode',20):$existing['meal_mode'];$offer=isset($_POST['offer_type'])?input('offer_type',20):$existing['offer_type'];
        $men=input('menu_en',2000);$mko=input('menu_ko',2000);
        if(!in_array($meal,array('reimburse','provided'))||!in_array($offer,array('menu','budget')))fail('invalid_input');
        if($active && $meal==='provided' && $offer==='menu' && (!$men||!$mko))fail('menu_required');
        $tags=isset($_POST['required_tags'])?normalized_tags(input('required_tags',2000)):$existing['required_tags'];$enforce=isset($_POST['required_tags'])?(input('enforce_tags',1)==='1'?1:0):$existing['enforce_tags'];
        query('UPDATE campaigns SET title_en=?,title_ko=?,branch_en=?,branch_ko=?,address_en=?,address_ko=?,guide_en=?,guide_ko=?,cap=?,reward=?,active=?,required_tags=?,enforce_tags=?,meal_mode=?,offer_type=?,menu_en=?,menu_ko=? WHERE id=?',array($en,$ko,$ben,$bko,$aen,$ako,input('guide_en',5000),input('guide_ko',5000),intval($cap),intval($reward),$active,$tags,$enforce,$meal,$offer,$men,$mko,$id));
        audit($u['id'],'campaign_update',$id);answer(array('ok'=>true));
    }
    if($action==='new_campaign') {
        need_admin();
        $name=input('client_name',150);if(!$name)fail('invalid_input'); begin_write();
        query('INSERT INTO clients(name) VALUES(?)',array($name));$client=db()->lastInsertId();$base=campaign(1);
        query('INSERT INTO campaigns(client_id,title_en,title_ko,guide_en,guide_ko,cap,reward) VALUES(?,?,?,?,?,?,?)',array($client,$name,$name,$base['guide_en'],$base['guide_ko'],50000,20000));
        $id=db()->lastInsertId();audit($u['id'],'campaign_create',$id);commit_write();answer(array('ok'=>true,'id'=>$id));
    }
    if($action==='slot') {
        $cid=intval(input('campaign_id'));$date=input('starts_at',20);$capacity=intval(input('capacity',3));
        manage_campaign($u,$cid);
        if(!campaign($cid)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/',$date)||!strtotime($date)||strtotime($date)<=time()||$capacity<1||$capacity>100)fail('slot_invalid');
        $date=date('Y-m-d H:i:s',strtotime($date));
        if(one('SELECT id FROM slots WHERE campaign_id=? AND starts_at=?',array($cid,$date)))fail('slot_duplicate');
        query('INSERT INTO slots(campaign_id,starts_at,capacity) VALUES(?,?,?)',array($cid,$date,$capacity));audit($u['id'],'slot_create',db()->lastInsertId());answer(array('ok'=>true));
    }
    if($action==='remove_slot') {
        $id=intval(input('id'));$slot=one('SELECT campaign_id FROM slots WHERE id=?',array($id));if(!$slot)fail('not_found',404);manage_campaign($u,$slot['campaign_id']);begin_write();if(one("SELECT id FROM bookings WHERE slot_id=?",array($id))){rollback_write();fail('slot_has_bookings');}
        query('DELETE FROM slots WHERE id=?',array($id));commit_write();answer(array('ok'=>true));
    }
    if($action==='schedule') {
        $cid=intval(input('campaign_id'));manage_campaign($u,$cid);$from=input('from',10);$to=input('to',10);$days=explode(',',input('days',20));$clock=input('time',5);$capacity=intval(input('capacity',3));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)||!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$clock)||$capacity<1||$capacity>100)fail('slot_invalid');
        $start=strtotime($from);$end=strtotime($to);if(!$start||!$end||date('Y-m-d',$start)!==$from||date('Y-m-d',$end)!==$to||$end<$start||$end-$start>90*86400||!count(array_intersect($days,array('0','1','2','3','4','5','6'))))fail('slot_invalid');
        begin_write();$count=0;
        for($d=$start;$d<=$end;$d+=86400){if(!in_array(date('w',$d),$days))continue;$at=date('Y-m-d',$d).' '.$clock.':00';if(strtotime($at)<=time())continue;$q=query('INSERT OR IGNORE INTO slots(campaign_id,starts_at,capacity) VALUES(?,?,?)',array($cid,$at,$capacity));$count+=$q->rowCount();}
        audit($u['id'],'schedule_create',$cid);commit_write();answer(array('ok'=>true,'count'=>$count));
    }
    need_admin();
    if($action==='blacklist_creator') {
        $id=intval(input('id'));$decision=input('decision',20);$reason=input('reason',30);$note=input('note',1500);$expected=input('expected',1);
        if(!in_array($decision,array('block','unblock')) || !in_array($expected,array('0','1')))fail('invalid_input');
        if(!$note)fail('note_required');
        if($decision==='block' && !in_array($reason,array('no_show','late_cancel','content_issue','other')))fail('invalid_input');
        if($decision==='unblock')$reason='release';
        begin_write();$target=one("SELECT id,is_blacklisted FROM users WHERE id=? AND role='influencer'",array($id));
        if(!$target){rollback_write();fail('not_found',404);}
        $next=$decision==='block'?1:0;
        if(intval($target['is_blacklisted'])!==intval($expected) || intval($target['is_blacklisted'])===$next){rollback_write();fail('invalid_status',409);}
        query('UPDATE users SET is_blacklisted=? WHERE id=?',array($next,$id));
        query('INSERT INTO blacklist_events(user_id,actor_id,action,reason,note,created_at) VALUES(?,?,?,?,?,?)',array($id,$u['id'],$decision,$reason,$note,date('c')));
        audit($u['id'],'blacklist_'.$decision,$id);commit_write();answer(array('ok'=>true));
    }
    if($action==='approve_creator') {
        $id=intval(input('id'));$status=input('status',20);$note=input('note',1000);
        if(!in_array($status,array('approved','rejected')))fail('invalid_input');
        if($status==='rejected' && !$note)fail('note_required');
        $q=query("UPDATE users SET approval_status=?,approval_note=? WHERE id=? AND role='influencer'",array($status,$note,$id));
        if(!$q->rowCount())fail('not_found',404);audit($u['id'],'creator_'.$status,$id);answer(array('ok'=>true));
    }
    if($action==='invite') {
        $role=input('role');$cid=intval(input('campaign_id'));$label=input('label',120);
        if(!in_array($role,array('influencer','client'))||!campaign($cid)||!$label)fail('invalid_input');
        $token=random_hex(24);query('INSERT INTO invites(token_hash,label,role,campaign_id,expires_at,created_at) VALUES(?,?,?,?,?,?)',array(hash('sha256',$token),$label,$role,$cid,time()+30*86400,date('c')));
        audit($u['id'],'invite_create',db()->lastInsertId());answer(array('ok'=>true,'url'=>($role==='client'?client_portal_url(campaign_client_id($cid)):'https://splabab.co.kr/inf/').'#'.(demo_mode()?'demo=1&':'').'invite='.$token));
    }
    if($action==='revoke_invite') { query('UPDATE invites SET expires_at=0 WHERE id=? AND used_by IS NULL',array(intval(input('id'))));answer(array('ok'=>true)); }
    if($action==='review') {
        $id=intval(input('id'));$decision=input('decision');$note=input('note',1500);begin_write();$b=booking_detail($id);
        if(!$b || $b['status']!=='submitted' || !in_array($decision,array('approve','changes'))) {rollback_write();fail('invalid_status');}
        if($decision==='changes') {if(!$note){rollback_write();fail('note_required');}query("UPDATE bookings SET status='changes',review_note=? WHERE id=?",array($note,$id));}
        else { if(input('content_checked',1)!=='1'){rollback_write();fail('content_check_required');} $refund=$b['meal_mode']==='provided'?0:min(intval($b['amount']),intval($b['cap']));query("UPDATE bookings SET status='approved',refund=?,total=?,approved_at=?,review_note=?,content_checked=1 WHERE id=?",array($refund,$refund+intval($b['reward']),date('c'),$note,$id));}
        audit($u['id'],$decision,$id);commit_write();answer(array('ok'=>true));
    }
    if($action==='paid') {
        $id=intval(input('id'));$ref=input('payment_ref',180);if(!$ref)fail('payment_ref_required');
        $q=query("UPDATE bookings SET status='paid',payment_ref=?,paid_at=? WHERE id=? AND status='approved'",array($ref,date('c'),$id));
        if(!$q->rowCount())fail('invalid_status');audit($u['id'],'paid',$id);answer(array('ok'=>true));
    }
    fail('not_found',404);
} catch(Exception $e) { rollback_write(); error_log('LABAB INF: '.$e->getMessage()); answer(array('error'=>'server_error'),503); }
