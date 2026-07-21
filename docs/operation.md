# Operation Guide

## Run Locally

Start the application locally:

```bash
php artisan serve
```

Open the Filament panel:

```text
http://127.0.0.1:8000/admin
```

## Run the Scheduler Locally

For local development or demonstration, run:

```bash
php artisan schedule:work
```

This keeps Laravel's scheduler running in the foreground.

## Configure Cron on a Server

On a production server, add one cron entry:

```cron
* * * * * cd /path/to/svu-quality-monitor && php artisan schedule:run >> /dev/null 2>&1
```

Replace `/path/to/svu-quality-monitor` with the actual project path on the server.

## Scheduled Tasks

The scheduler runs:

- `php artisan services:check-due` every minute
- `php artisan reliability:calculate --period=daily` daily at `00:10`
- `php artisan control-charts:calculate --period=daily` daily at `00:25`

Each scheduled command uses `withoutOverlapping()` to reduce duplicate concurrent runs.

## Useful Commands

```bash
php artisan services:check-due
php artisan reliability:calculate --period=daily
php artisan control-charts:calculate --period=daily
php artisan test
php artisan optimize:clear
npm run build
```

## Verification Steps

1. Open `/admin`.
2. Check the dashboard.
3. Open a monitored service and run **Check Now**.
4. Export a standard Excel report.
5. Export the comprehensive PDF report.
6. Export the Minitab-ready Excel file.
7. Confirm scheduled commands are listed:

```bash
php artisan schedule:list
```
