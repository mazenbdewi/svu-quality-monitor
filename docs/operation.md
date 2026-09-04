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

## Run with Docker in Production

Start the complete application stack with:

```bash
docker compose up -d
```

This starts `app`, `web`, `db`, `scheduler`, and `queue`. The scheduler and queue worker are required services, not optional profiles. Docker restarts them using the `unless-stopped` policy after a server or Docker restart.

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

For service checks, the command only identifies due active services and dispatches one `CheckMonitoredServiceJob` per service. The queue worker executes the HTTP check through `ServiceCheckRunner`. Each job is unique per monitored service and also has a short execution lock, so repeated scheduling, retries, or a slow request cannot run two checks for the same service concurrently. The uniqueness lock expires safely if a worker dies.

## Background Health Rules

The System Operations page records and displays runtime heartbeats; it does not infer health merely from the presence of a Docker container.

- Scheduler heartbeat: written by a Laravel scheduled task every minute. It is **Healthy** through 3 minutes since the last heartbeat, **Warning** until 5 minutes, and **Down** after 5 minutes.
- Queue heartbeat: written by the queue worker polling loop even when there are no jobs. It is **Healthy** through 90 seconds, **Warning** until 3 minutes, and **Down** after 3 minutes.
- The same page also shows the last successful and failed queue jobs, pending and failed job counts when the database queue is active, automatic-monitoring activity, database connectivity, environment, version, server time, and timezone.

The thresholds can be adjusted through the `MONITORING_SCHEDULER_*` and `MONITORING_QUEUE_*` environment variables defined in `config/monitoring.php`.

## Automatic and Manual Checks

Checks now retain their source. Jobs dispatched by the scheduler are stored as `automatic`; **Check Now** continues to run immediately and is stored as `manual`. This lets the operations page report automatic-monitoring activity without treating a manual check as scheduler proof.

## Failed Jobs

The worker retries automatic service-check jobs up to three times. Infrastructure failures are recorded by Laravel in `failed_jobs`; the job logs only the monitored-service ID and exception class, never service credentials or request secrets. A dispatch failure for one service is logged and does not stop dispatching other due services.

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
