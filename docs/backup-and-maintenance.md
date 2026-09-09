# Backup and Maintenance

> Superseded for phase 9: use [Backup and restore](backup-and-restore.md) and
> [Safe updates](safe-update.md). The old commands below are reference only;
> `.env` must be saved separately in secure secret storage, never in ordinary bundles.

## Current tools

Use the guarded commands in the linked guides:

```bash
php artisan backup:create --actor-email=EXISTING_ADMIN_EMAIL
php artisan backup:verify /path/to/BUNDLE --actor-email=EXISTING_ADMIN_EMAIL
```

## Restore

```bash
php artisan backup:restore /path/to/BUNDLE --force --workers-stopped --actor-email=EXISTING_SUPER_ADMIN_EMAIL
```

## Files to Back Up

Back up:

- The database
- `storage/app/public` and `storage/app/private`

Keep `.env` and APP_KEY separately in secure secret storage. Stop all writers and
follow the restore runbook before running the destructive command above.

Do not store backups in a public web directory.

## Maintenance Commands

```bash
php artisan optimize:clear
php artisan view:clear
php artisan cache:clear
npm run build
```

## Security Reminders

- Do not commit `.env`.
- Keep `APP_DEBUG=false` in production.
- Do not expose logs publicly.
- Use HTTPS in production.
- Keep regular backups.
- Store database credentials securely.
- Monitor application logs and server disk space.
