# Safe application updates — phase 9

Run `python3 scripts/safe-update.py --url http://127.0.0.1:8081 --dry-run`
from the trusted release directory, then repeat without `--dry-run` to apply.
Python 3, Docker Compose v2, adequate disk and Docker administrator access are
required. `--compose` and `--state` allow explicit project/state paths. Existing
Compose project identity, environment and volumes MUST match the running release.
The URL must be the trusted deployment's base URL, not an unrelated healthy site.
File-based Laravel maintenance with the shared application_storage volume is
required and checked by preflight. Cache-based maintenance is rejected.

No git fetch/pull/reset or checkout occurs. The input is an operator-reviewed
working tree/release package; dirty trees are accepted as the exact build input.
Keep source unchanged during update. A Git checkout is optional. Production
release authenticity/distribution is an operator responsibility.

## Bootstrap prerequisite

The currently running image must already provide phase 9 backup and smoke tools,
and its app/scheduler must mount backup_data. Otherwise preflight fails closed.
For the first phase 9 rollout, use a reviewed one-off phase 9 tooling container
with the same DB/storage and dedicated backup volume to create a verified backup
before switching, following the phase 8 safe deployment procedure. This script
does not pretend older images have backup commands. No live deployment is needed
to run the isolated restore drill.

## Sequence

1. Check Docker, compose syntax, current app/DB/schema/cache/storage/heartbeats,
   active Super Admin, login HTTP, backup directory writable, host and backup free
   disk. The minimum reserve is 1 GiB; size your host for build layers as well.
2. Take a host lock and create private update-state timestamped logs. Pin all four
   previous images with recovery tags. The log stores identifiers, not environment
   values or command stderr. Do not prune recovery images before acceptance.
   Preflight tries the container's immutable image ID, then Compose's image label
   for stores that expose different config/manifest digests, verifying each. It
   fails closed when a prior image is missing; it never substitutes a mutable
   latest tag that may already refer to new code. Preserve images before manual builds.
3. Mandatory verified pre-update backup. Failure aborts before build/migrations.
4. Build app/scheduler/queue/web with old web still online. Build failure leaves
   the running release online. Show pending migrations from the new image.
5. Enable maintenance, signal `queue:restart`, stop scheduler/queue gracefully
   (120-second grace vs current 60-second job timeout). Increase grace if adding
   longer tasks. External writers must already be controlled by the operator.
6. Run migrate --force and the idempotent RBAC seeder in a new one-off app image.
   Release migrations must be reviewed for compatibility and acceptable downtime.
7. Recreate app/scheduler/queue then web with --no-deps --no-build. DB is never
   recreated. Recreating nginx refreshes PHP-FPM DNS/IP after app replacement.
8. Clear app and permission caches and run static smoke checks. Stop web, then
   leave Laravel maintenance so the queue can emit real looping heartbeats.
   Wait up to 180 seconds for full smoke health while HTTP stays closed; start
   web and check login HTTP. Never manufacture heartbeats to pass acceptance.

The shared maintenance file keeps all app generations offline while necessary.
During the final heartbeat check, stopped web keeps HTTP closed while Laravel
maintenance is disabled; background jobs may run and write data during this check.
HTTP login verification happens immediately after reopening; if it fails the
script re-enables maintenance. There is a small acceptance window at this point;
do not assume zero downtime or zero writes during failure recovery.

## Failure and rollback

Before maintenance: no switch; previous containers continue. After maintenance:
failure is logged, maintenance retained/re-enabled, workers stopped, and previous
app/web images restored from pinned tags via a generated Compose override. If
Laravel cannot boot, rollback first stops web and writes the standard file-based
maintenance marker using standalone PHP before switching images. If
that recovery itself fails, the script emits `rollback_incomplete` and exits
nonzero; retain maintenance and inspect the saved tags and pre-update backup.

Database migrations are NOT automatically reversible. No migrate:rollback or
destructive DB restore is run. Old code may be incompatible with new schema: keep
it offline. Review migration effects. If compatible, restart workers with the
previous-images override, run smoke checks, then up. If not, use the pre-update
backup with the matching image according to backup-and-restore.md. The restore
command requires matching migration sets; cross-schema recovery requires a
separately provisioned matching recovery database and deliberate DB connection
switch, preserving the failed schema for investigation. Do not erase new writes
without explicit operator acceptance. This is a controlled recovery runbook, not
automatic transactional rollback.

Record locations: `update-state/<UTC timestamp>/events.jsonl` and, on rollback,
`previous-images.json`. Backup checkpoints live in backup_data. Back up both
off-host. Events include start, verified backup, build, migration status/completion,
new image IDs, switch, health result and rollback. Host updates have operational
logs, no invented human audit actor.

Dry-run performs only preflight and prints image IDs/steps; it does not build,
backup, migrate, stop containers or create update-state files. The smoke cache
probe briefly writes and deletes a uniquely named cache entry.

## Verification

`php artisan system:smoke-check` checks DB, critical schema, pending migrations,
active administrative access, cache read/write, writable storage and real
Scheduler/Queue heartbeat. `--static` deliberately skips heartbeat only during
offline investigation; safe-update uses the full check. It makes no external
monitoring probes. Shell-flow regression uses a fake process adapter:
`python3 -m unittest discover -s tests -p 'safe_update_test.py'`.
