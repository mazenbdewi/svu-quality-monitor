# Safe application updates — phase 9

Run the commands below from `/var/www/svu-quality-monitor` after installing the
reviewed committed release there. Production HTTP is `http://127.0.0.1:8080`.
Run preflight with `--dry-run`, then repeat without it to apply.
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

## Direct upgrade from production

Direct upgrade from production commit `d15905ede05f0323f49ec2e1b5a79514282e6340` is supported;
installation of `58a097f` as an intermediate release is unnecessary. Source
comparison confirms that production already supplies `backup:create`,
`backup:verify`, `system:smoke-check`, and the required shared storage/backup
volumes. Its migration file set is identical to `58a097f`; this batch adds only
`2026_11_18_000001_create_baseline_preparation_runs.php`. The old running app's
smoke command does not require the newly introduced baseline worker. Preflight
accepts its absence, builds the cumulative new release, then applies all pending
migrations before starting the new worker. The Docker upgrade rehearsal used
`58a097f`; the `d15905e` confirmation is source/prerequisite compatibility review,
not a claim of a second Docker rehearsal.

Retain the production `.env`, application key, database identity, Compose project
identity and named volumes. Keep these existing production settings:

```dotenv
APP_PORT=8080
CACHE_STORE=file
QUEUE_CONNECTION=database
MONITORING_FIXED_CADENCE_ENABLED=false
SLO_AUTO_PREPARATION_ENABLED=false
```

Shared **file cache is supported**. App, scheduler and workers mount the same
`application_storage` volume at `/var/www/html/storage`; file cache carries
heartbeats and restart signals. Dispatch identities, fairness and the mutex live
in SQL and survive cache clearing. A database cache store is not required. File
maintenance is a separate setting and remains required. The database queue must
use the application database connection with `after_commit=false`.

The procedure preserves historical measurements, approved baselines, SLO
objectives and existing permissions: it applies the additive migration without
seeding, resetting permissions, recreating the database, or purging queues. New
Phase I outputs are drafts. No user-accessible manual Phase I calibration-period
selection is registered. Fixed Cadence and automatic SLO preparation remain off;
npm remediation is a separate batch.

For an air-gapped production Docker daemon, use the complete build, checksum,
transfer, import and `--prebuilt` procedure in
[offline-deployment.md](offline-deployment.md). Prebuilt validation fails before
maintenance if any required or active optional worker image is absent, has the
wrong platform/ID, or does not carry the expected release identity.

## Sequence

1. Check Docker, compose syntax, current app/DB/schema/cache/storage/heartbeats,
   active Super Admin, login HTTP, backup directory writable, host and backup free
   disk. The minimum reserve is 1 GiB; size your host for build layers as well.
2. Take a host lock and create private update-state timestamped logs. Pin all existing
   previous images, including dedicated workers with recovery tags. The log stores identifiers, not environment
   values or command stderr. Do not prune recovery images before acceptance.
   Preflight tries the container's immutable image ID, then Compose's image label
   for stores that expose different config/manifest digests, verifying each. It
   fails closed when a prior image is missing; it never substitutes a mutable
   latest tag that may already refer to new code. Preserve images before manual builds.
3. Mandatory verified pre-update backup. Failure aborts before build/migrations.
4. In online mode, build app/scheduler/queue/baseline_queue/web and active
   optional workers with old web still online. Build failure leaves the running
   release online. In prebuilt mode, use only the validated and pinned local
   images. Show pending migrations from the selected new app image.
5. Enable maintenance, signal `queue:restart`, stop scheduler/queue/baseline_queue and active optional workers gracefully
   (120-second grace vs current 60-second job timeout). Increase grace if adding
   longer tasks. External writers must already be controlled by the operator.
6. Run migrate --force in a new one-off app image. No seeder runs and existing role/permission assignments are preserved.
   Release migrations must be reviewed for compatibility and acceptable downtime.
7. Recreate app/scheduler/queue/baseline_queue and active optional workers, then web with --no-deps --no-build. DB is never
   recreated. Recreating nginx refreshes PHP-FPM DNS/IP after app replacement.
8. Clear application caches and run static smoke checks. Stop web, then
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
probe briefly writes and deletes a uniquely named cache entry. With `--prebuilt`,
dry-run also validates the manifest, local image IDs, architecture and labels,
without tagging images or contacting a registry.

## Verification

`php artisan system:smoke-check` checks DB, critical schema, pending migrations,
active administrative access, cache read/write, writable storage and real
Scheduler/normal-queue/baseline-queue heartbeat. `--static` deliberately skips heartbeat only during
offline investigation; safe-update uses the full check. It makes no external
monitoring probes. Shell-flow regression uses a fake process adapter:
`python3 -m unittest discover -s tests -p 'safe_update_test.py'`.


## Baseline worker upgrade and recovery

The source Compose definition must contain `baseline_queue`; the running old
installation may have no such container. Preflight requires the four existing
core containers and records every existing worker image. Inactive optional
profiles remain inactive. An existing optional `*_queue` worker or a worker in
`COMPOSE_PROFILES` is built, pinned, stopped, and recreated with the same batch.
New build images are also frozen under `svu-release-<service>:<timestamp>` and
recorded in `release-images.json` before migrations or container recreation.

The additive migration creates `baseline_preparation_runs` and a one-row
`baseline_preparation_scheduler` table. Both must remain with pending work.
Queue insertion and ledger/cursor changes share an application-database
transaction. Baseline dispatch requires the database queue on the application
connection, with `after_commit=false`; external brokers and delayed commits
are rejected explicitly because they cannot provide that atomic guarantee.
Do not purge these tables, flush the queue, or reset the cursor during updates.
Failed jobs are retained for investigation and explicit `queue:retry <uuid>`.
Retries retain their ledger identity; pending/retried legacy payloads also count
toward duplicate protection and backlog capacity.

On rollback all background workers stay stopped, including the baseline worker.
`baseline_queue` is removed, and old app/web images return in maintenance mode.
Pending queue rows and both new tables remain untouched. An older image may not
understand the serialized new job fields or the new dispatch ledger. **Do not
start an old baseline worker or route baseline-preparation onto the normal
queue.** Keep the old scheduler stopped until its baseline preparation schedule
is disabled in a reviewed recovery package; otherwise it could enqueue jobs
without the new bounds. Normal monitoring can resume only after schema/code
compatibility and the recovery package are checked. Prefer forward repair with
the corrected release so it can drain retained jobs. A database restore requires
separate operator authorization and reconciliation of writes made after backup.

Production commands, after installing the reviewed release in the existing directory:

```bash
cd /var/www/svu-quality-monitor
python3 scripts/safe-update.py --compose /var/www/svu-quality-monitor/compose.yaml \
  --state /var/www/svu-quality-monitor/update-state \
  --url http://127.0.0.1:8080 --dry-run
python3 scripts/safe-update.py --compose /var/www/svu-quality-monitor/compose.yaml \
  --state /var/www/svu-quality-monitor/update-state \
  --url http://127.0.0.1:8080
```

Automatic failure recovery uses the saved overrides. To recreate only the old
app/web manually, first stop web and all workers, establish file maintenance,
and use the recorded recovery images (never a mutable `latest` tag):

```bash
docker compose -f /var/www/svu-quality-monitor/compose.yaml stop -t 120 web scheduler queue baseline_queue
docker compose -f /var/www/svu-quality-monitor/compose.yaml exec -T app php artisan down --retry=60
# Also stop every active optional worker recorded in events.jsonl.
docker compose -f /var/www/svu-quality-monitor/compose.yaml \
  -f update-state/<run>/previous-images.json \
  up -d --no-deps --no-build --force-recreate app web
```

Keep maintenance enabled while reviewing compatibility. Do not use
`migrate:rollback`, `queue:clear`, `queue:flush`, or a full database seeder as a
routine release recovery step. Successful safe-update explicitly runs `artisan
up`, proves worker heartbeats with HTTP closed, and then reopens and verifies HTTP.
