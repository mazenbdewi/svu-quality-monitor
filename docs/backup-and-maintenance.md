# Backup and Maintenance

## Database Backup

Use placeholders for documentation and replace them only on the actual server:

```bash
mysqldump -u USER -p DATABASE_NAME > backup.sql
```

## Database Restore

```bash
mysql -u USER -p DATABASE_NAME < backup.sql
```

## Files to Back Up

Back up:

- `.env`
- The database
- `storage/` if uploaded or generated files need to be preserved

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
