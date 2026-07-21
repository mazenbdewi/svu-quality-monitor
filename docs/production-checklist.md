# Production Checklist

Before deploying or presenting the system in a production-like environment, review this checklist.

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] `APP_KEY` is set
- [ ] Database credentials are configured securely
- [ ] Scheduler cron is configured
- [ ] HTTPS is configured
- [ ] Storage permissions are checked
- [ ] Logs are monitored
- [ ] Backups are configured
- [ ] Tests pass before deployment
- [ ] `composer install` completed
- [ ] `npm run build` completed
- [ ] `.env` is not committed
- [ ] Server path in cron matches the actual project path
- [ ] Public document root points to the Laravel `public/` directory
