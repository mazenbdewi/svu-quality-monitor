# SLA and Error Budget

SLA is configured per service as a decimal target (for example `99.90`). SLO is deliberately deferred: a second target would reuse the same calculation but has no separate policy or UI in this phase.

For a requested period, the calculator uses `max(period start, service created_at)` and `min(period end, now)`. This prevents future time and time before a service existed from entering the denominator. Boundaries use the application timezone.

Eligible observation time is observation seconds minus merged planned-maintenance intervals supplied by `MaintenanceWindowService`. Unplanned downtime is only confirmed incidents, clipped to the period (open incidents end at `now`) and with maintenance intervals removed.

`availability = (eligible - unplanned downtime) / eligible * 100`.

`allowed downtime = eligible * (1 - target / 100)`. Error budget consumed is unplanned downtime; remaining budget can be negative and consumed percent is not capped. A service is at risk after the configured 80% budget threshold, and breached when actual availability is below the target. Zero eligible time is `no_data`, never 100%.

Monthly snapshots are idempotent by service and period. They preserve the target used for the calculation; changing a target later does not rewrite historical snapshots. Full target versioning within an already-open month is intentionally not implemented.
