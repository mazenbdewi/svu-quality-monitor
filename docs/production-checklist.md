# Production acceptance checklist

Use this checklist for the existing Docker deployment; it is not an installer.
The dated evidence and outstanding items are in [final-readiness-report.md](final-readiness-report.md).
The public-deployment closure is in [public-deployment-closure.md](public-deployment-closure.md):
**internal/academic READY; public internet NEEDS ATTENTION**. Optional HTTPS/off-host
configuration is prepared but not activated. Latest real-backup restore and controlled
HTTP incident/recovery acceptance passed in a disposable environment, which was removed.

## Before deployment

- [x] Application pages, expected RBAC denials, Arabic/English and RTL acceptance tests.
- [x] Monitoring/incidents/maintenance, SLA/reliability/SPC and report regression tests.
- [x] Full PHP suite twice on final application code; Pint and whitespace checks.
- [x] Production Composer advisory audit after security updates.
- [x] `.env` excluded from Git/images; APP_KEY retained; production env and Debug off.
- [x] Build app/web/scheduler/queue and publish matching Filament assets in the image.
- [x] Record DB container/volume IDs, administrative access and baseline data fingerprints.
- [x] Create and independently verify a full pre-deployment backup.
- [x] Preserve recoverable previous app/web images; no automatic DB restore.

## Deployment and acceptance

- [x] Maintenance, graceful worker stop, `migrate --force`, idempotent RBAC-only seeder.
- [x] Recreate app/web/scheduler/queue with `--no-deps --no-build`; never recreate DB.
- [x] Clear caches, verify new runtime heartbeats, reopen and check login HTTP.
- [x] Confirm production env/Debug, active Super Admin and unchanged baseline data.
- [x] Review System Diagnostics and the schedule (8 unique entries, UTC on this host).
- [x] Runtime `backup:create` + independent `backup:verify`; **no Restore**.
- [x] `python3 scripts/safe-update.py --url http://127.0.0.1:8081 --dry-run`.

Docker already runs `scheduler` using `schedule:work`. Do not install host cron
or a second scheduler. For non-Docker instructions only, see operation.md.
Use safe-update.md for subsequent updates, not a blind `docker compose up -d`
or destructive compose down/volume reset.

## Operator-owned production prerequisites

- [ ] HTTPS/trusted reverse proxy and secure session cookies before public use.
- [ ] Restricted host/network access; no unreviewed Docker socket exposure.
- [ ] Off-host backups and securely stored `.env`/APP_KEY; bundles are not encrypted.
- [ ] Configured real monitored services and required notification channels.
- [ ] Ongoing host/disk/log monitoring and periodic isolated restore drills.

Application acceptance does not imply completion of unchecked infrastructure or
operator-owned prerequisites. Empty live datasets are not evidence of live SLA/SPC results.
