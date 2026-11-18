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

## Configure Cron on a Non-Docker Server

Only when NOT using the Docker scheduler service, add one cron entry:

```cron
* * * * * cd /path/to/svu-quality-monitor && php artisan schedule:run >> /dev/null 2>&1
```

Replace `/path/to/svu-quality-monitor` with the actual project path on the server.
Do not add this cron alongside Docker `scheduler` / `schedule:work`, or tasks may run twice.

## Scheduled Tasks

The scheduler runs:

- `php artisan services:check-due` every minute
- `system-health:scheduler-heartbeat` every minute
- `php artisan system:check-background-health` every 5 minutes
- `php artisan reliability:calculate --period=daily` daily at `00:10`
- `php artisan sla:calculate` daily at `00:20`
- `php artisan control-charts:calculate --period=daily` daily at `00:25`
- `php artisan slo:prepare-due` daily at `00:35`, dispatching bounded research-only preparation jobs
- `php artisan backup:create --scheduled` daily at `02:00`
- `php artisan backup:cleanup --scheduled` daily at `03:00`

Scheduled commands use `withoutOverlapping()` to reduce duplicate concurrent runs;
the lightweight heartbeat is a named callback. Daily times follow the institution timezone.

For service checks, the command only identifies due active services and dispatches one `CheckMonitoredServiceJob` per service. The queue worker executes the HTTP check through `ServiceCheckRunner`. Each job is unique per monitored service and also has a short execution lock, so repeated scheduling, retries, or a slow request cannot run two checks for the same service concurrently. The uniqueness lock expires safely if a worker dies.

## Background Health Rules

System Operations displays runtime heartbeats recorded by the scheduler and queue worker;
opening the page does not record a heartbeat or infer health from a Docker container.

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


## Automatic Phase I preparation worker

Keep `MONITORING_FIXED_CADENCE_ENABLED=false` and
`SLO_AUTO_PREPARATION_ENABLED=false` for this release. Automatic Phase I draft
preparation runs independently through `baselines:prepare-due` at 00:30 in the
institution timezone and the dedicated `baseline_queue` service. It creates
reviewable drafts only. Start it with the normal application Compose services;
it has no optional profile.

`BASELINE_PREPARATION_BACKLOG_LIMIT` (default 25) bounds retained pending and
reserved work. The scheduler serializes backlog/dispatch decisions with a durable
SQL row and commits queue rows, run identities, and its round-robin cursor in one
transaction. Each invocation enqueues at most five successful unique jobs. Both
scheduled and researcher-triggered preparation use this path. A worker outage
cannot grow duplicate jobs after cache lock expiration or on later days.

System Operations and diagnostics show a dedicated heartbeat, pending jobs,
reserved jobs, retained failures, oldest pending age, last successful draft
preparation, and backlog limit. A reservation is evidence of ownership, not proof
that a process is alive; after interruption it may remain reserved until the
normal queue retry interval (90 seconds by default). The label states this
limitation. Idle workers continue emitting heartbeats. Default warning thresholds
are 20 queued/reserved jobs or 3,600 seconds for the oldest pending job; startup
grace is 180 seconds. Configure these with
`BASELINE_PREPARATION_BACKLOG_WARNING`,
`BASELINE_PREPARATION_OLDEST_WARNING_SECONDS`, and
`BASELINE_PREPARATION_STARTUP_GRACE_SECONDS`.

Existing background-health alerts open a baseline incident for a stale worker,
excessive backlog, old pending work, or retained failures. They recover when the
worker and queue return to healthy conditions. Startup grace suppresses a missing
first-heartbeat alert, while genuine backlog problems remain alertable. Both the
operations UI and baseline notifications support Arabic and English.

Investigate a delayed queue with:

```bash
docker compose ps baseline_queue
docker compose logs --tail=100 baseline_queue
docker compose exec app php artisan baselines:prepare-due
docker compose exec app php artisan queue:failed
docker compose exec app php artisan system:check-background-health
```

After fixing a failure, retry its specific UUID with `queue:retry <uuid>`. Never
purge failed jobs or pending work to make a health check green. Keep the dedicated
worker restart policy enabled: Laravel intentionally exits workers that exceed
its recycling memory threshold. The container policy restarts them; the SQL queue
and run ledger preserve work. See [safe-update.md](safe-update.md) before rollback
with pending baseline jobs.


### Known monitoring contention

During isolated backlog recovery, one normal monitoring job took **21.3 seconds**
while Phase I sample archiving held the same service row. The recovery p95 was
233 ms; this does not eliminate the observed wait. Budget for this contention
when draining a baseline backlog and watch normal queue latency alongside the
baseline worker. These shared-host measurements are not a production latency
or capacity guarantee. Historical measurements and approved research decisions
remain intact; do not purge work to reduce the displayed backlog.

Production uses `/var/www/svu-quality-monitor` and HTTP port `8080`. Shared
`CACHE_STORE=file` is supported through the common `application_storage` volume;
durable dispatch state and locking use SQL independently of the cache. See
[safe-update.md](safe-update.md) for the direct upgrade from production `d15905e`.
