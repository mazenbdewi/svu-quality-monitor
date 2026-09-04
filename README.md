# SVU Quality Monitor

SVU Quality Monitor is a Laravel and Filament based research-support system for monitoring electronic services, analyzing reliability, building statistical control charts, and exporting research reports.

## Main Features

- Monitored services
- Lightweight service checks
- Manual **Check Now** action
- Scheduled checks
- Incident detection
- Reliability metrics
- Statistical control charts
- Monitoring dashboard
- Research interpretation and recommendations
- Excel reports
- Comprehensive PDF report
- Minitab-ready export
- Arabic/English interface with RTL/LTR support

## Safe Use

The system performs lightweight checks only. It does not perform stress testing, security scanning, penetration testing, or access personal user data.

## Documentation

- [Installation Guide](docs/installation.md)
- [Operation Guide](docs/operation.md)
- [Monitoring Engine](docs/monitoring-engine.md)
- [Incident State Machine](docs/incident-state-machine.md)
- [Planned Maintenance Windows](docs/planned-maintenance.md)
- [Notification Engine](docs/notifications.md)
- [Research Workflow](docs/research-workflow.md)
- [Backup and Maintenance](docs/backup-and-maintenance.md)
- [Production Checklist](docs/production-checklist.md)
- [Minitab Validation Guide](docs/minitab-validation.md)

## Quick Start

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan make:filament-user
npm install
npm run build
php artisan serve
```

Open the Filament panel at:

```text
http://127.0.0.1:8000/admin
```

## Scheduler

For local operation:

```bash
php artisan schedule:work
```

For server operation, configure cron:

```cron
* * * * * cd /path/to/svu-quality-monitor && php artisan schedule:run >> /dev/null 2>&1
```

## Docker Background Processing

For a production-style Docker run, use:

```bash
docker compose up -d
```

The command starts the scheduler and queue worker alongside the web application and database. Runtime heartbeat and queue status are visible on **System Operations** in the admin panel.
