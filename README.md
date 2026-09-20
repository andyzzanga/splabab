# SPLABAB Influencer Rewards

The influencer campaign, reservation, reimbursement, and reward application served from `https://splabab.co.kr/inf/`.

## Team workflow

1. Create a branch from `main` for each change.
2. Commit and push the branch.
3. Open a pull request.
4. Merge after another team member reviews it.

Production databases, member information, receipts, sessions, setup tokens, and credentials are not stored in Git.

## Production

- Host: AWS Lightsail (`splabab-prod-01`)
- Application path: `/srv/splababcokr/inf`
- Persistent data path: `/var/lib/labab-inf` (not tracked)

## Collaboration repository

https://github.com/andyzzanga/splabab

공동 작업 시 변경사항은 PR로 검토한 후 합칩니다.

Imported from production source commit `fb17439` on 2026-09-20. Runtime databases and uploads are excluded.
