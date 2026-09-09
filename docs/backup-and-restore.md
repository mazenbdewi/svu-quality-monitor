# Backup and restore — phase 9

## Scope and storage

`BackupService` creates a directory bundle (not a tar archive) in the dedicated
`backup_data` Docker volume, mounted at `/var/backups/svu-quality-monitor` in app
and scheduler. It survives application recreation and is never mounted in nginx.
Directories are 0700 and bundle files 0600. Use the same OS identity for all backup
commands (the default Docker app CLI user is root). Treat the Docker daemon as
privileged administrative access. Copy bundles off-host using a protected channel;
a volume on the same disk is not disaster protection.

Each bundle contains `database.sql`, `files/public`, `files/private`,
`metadata.json`, and `manifest.json`. Sources are an explicit allowlist:
`storage/app/public` and `storage/app/private`, including future local logos.
External S3 storage and remote logo URLs are NOT downloaded. Empty directories
are preserved. Symlinks and special files are rejected.

Excluded: `.env`, logs, sessions, compiled views, caches, vendor, node_modules,
images. Save `.env` separately in a secret manager, especially the original
APP_KEY: encrypted DB fields cannot be recovered without it. The DB dump itself
contains sensitive application data and password hashes; it is NOT a sanitized
export or encrypted backup. No credentials or tokens are put into metadata/logs.

## Commands

```bash
docker compose exec -T app php artisan backup:create --actor-email=EXISTING_ADMIN_EMAIL
docker compose exec -T app php artisan backup:verify /var/backups/svu-quality-monitor/BUNDLE --actor-email=EXISTING_ADMIN_EMAIL
docker compose exec -T app php artisan backup:cleanup --actor-email=EXISTING_ADMIN_EMAIL
```

Replace placeholders with a verified existing account. Manual create/cleanup
require active `backups.create`; verification requires `backups.view` when an
actor is supplied. Restore requires an active super_admin. An actor-less verify
is a read-only OS-admin operation. Scheduler uses `--scheduled`; deployment uses
`--pre-update`, both operational rather than fictitious human audit. Never expose
these flags to untrusted users. Human operations record the requested backup.*
audit events; scheduled work records operational status instead.

MySQL 8.4 client tools are copied from a pinned official MySQL image into the PHP
runtime. `mysqldump` uses single-transaction, quick, skip-lock-tables,
no-tablespaces, set-gtid-purged=OFF, hex-blob, routines, events and triggers.
Credentials use a short-lived 0600 options file, removed in finally, not argv.
The configured account needs SELECT, SHOW VIEW, TRIGGER, EVENT and permissions to
read routines; restore needs DDL/DML on its target schema. No root access is
required for ordinary backup. InnoDB is required. Do not run schema changes
concurrently with a dump. Live DB and files are not an atomic cross-system
snapshot; for exact attachment consistency stop all writers first. Network DB
deployments must additionally configure trusted TLS rather than assuming local
Compose transport is suitable for a remote database.

Metadata format 1 records timestamp, app/Laravel/MySQL versions, ordered migration
names, components, file count and dump size. Manifest lists every payload file's
size and SHA-256. Verification rejects missing/extra files, symlinks, traversal,
bad format, corrupt checksums and non-MySQL dumps. Checksums detect accidental
corruption, NOT malicious modification: only restore trusted operator-owned
bundles. SQL validity is preliminary; an isolated restore drill is essential.

## Records, health and retention

Safe JSON run records live under `.runs` outside bundles, so restoring the DB does
not erase recovery history. No `backup_runs` DB table is necessary. A filesystem
lock serializes create/restore/cleanup across app and scheduler. Interrupted
operations retain a `running` record for investigation; incomplete bundles are
never eligible for cleanup.

Default retention: latest 14 verified known bundles. Unknown/corrupt bundles are
never removed. Pre-update and pre-restore checkpoints are protected from automatic
deletion; archive/delete them explicitly after accepting recovery risk. Run
records are retained. `BACKUP_KEEP_LAST`, `BACKUP_MINIMUM_FREE_BYTES` (1 GiB safety
reserve, plus estimated dump/files space), `BACKUP_WARNING_HOURS` (30),
`BACKUP_DOWN_HOURS` (54), `BACKUP_TIMEOUT` (1800 seconds) are configurable.

Scheduler creates a backup at 02:00 and cleans up at 03:00 in **APP_TIMEZONE**
(Laravel's configured timezone; verify the deployed value). This avoids existing
00:10/00:20/00:25 calculations. Failure is recorded and logged with safe messages.
System Operations shows last success/failure, age, size and status only to
`backups.view`. Administrator has view/create, super_admin has restore through
Gate; operator/viewer have no backup access. Run RolesAndPermissionsSeeder on
deployment: phase 9 totals are 4 roles, 32 permissions, 84 role-permission links.

## Restore (destructive; CLI only)

Stop external writers and drain scheduler/queue. Use a compatible application
image, the same APP_KEY, MySQL major version and exact migration set. Automatic
migrations are NOT part of restore. A backup from a different migration set is
rejected; choose its matching image first. Keep bundles and backups directory
operator-owned, not user-uploadable.

```bash
docker compose exec -T app php artisan down --retry=60
docker compose exec -T app php artisan queue:restart
docker compose stop -t 120 scheduler queue
docker compose exec -T app php artisan backup:restore /var/backups/svu-quality-monitor/BUNDLE --force --workers-stopped --actor-email=EXISTING_SUPER_ADMIN_EMAIL
```

Restore first verifies, stages a private copy and re-verifies, checks compatibility,
then creates a verified pre-restore checkpoint. Failure before that point does not
touch DB/files. It enables maintenance, imports SQL (dump contains DROP/CREATE),
replaces allowlisted files, clears caches and verifies an active Super Admin.
SQL import replaces tables present in the dump; unexpected manually-created tables
are not automatically dropped. Supported targets use the application's managed
schema only. Inspect schema drift before disaster restore.

**Success AND failure leave maintenance enabled.** No transaction spans DB/files.
If import or file replacement fails, keep all writers stopped and use the verified
pre-restore checkpoint with the compatible image. Do not silently reopen a partial
restore. No destructive automatic rollback is attempted. If the original actor
does not exist in the restored DB, inspect the restored administrative account
before reopening; audit failure keeps the site offline.

After successful restore verify ownership (`www-data` for private application
files; public files must be readable by nginx), restart workers, wait for fresh
heartbeats, run `system:smoke-check`, then `artisan up` and check `/admin/login`.
The scheduler heartbeat is explicitly allowed during maintenance. Queue's loop
heartbeat continues while actual jobs wait for maintenance to end.

## Disaster recovery

On a new host: install Docker/Compose, obtain the matching trusted project/image,
restore `.env` securely with its original APP_KEY, create empty named volumes,
start db, create the matching schema using migrations and seed roles. Re-establish
the authorized administrative recovery account using your controlled account
recovery process (never install default credentials). Transfer the trusted bundle
to the backup volume with 0700/0600 permissions. Keep app in maintenance and all
workers stopped. Execute verify/restore as above, check database, accounts, files,
permissions and heartbeat, then reopen. Preserve the pre-restore checkpoint.

For a totally empty DB where there is no existing actor, restore requires an
explicit operator-created recovery account before running the guarded command.
This is a deliberate CLI safety limitation, not an installer or setup wizard.

## Isolated drill

`scripts/verify-backup-isolated.sh` runs a dedicated Compose project with tmpfs
MySQL and temporary application storage, no production env_file or volumes. It
creates fixture data, backs up, mutates rows/files, restores and asserts recovery.
Run after building the phase 9 app image. Never point it at the production schema.
