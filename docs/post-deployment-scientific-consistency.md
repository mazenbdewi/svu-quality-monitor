# Post-deployment scientific consistency correction

## Phase I preparation and governance

`phase1-automatic-v1` is the only user-facing Phase I selection policy. A daily
institution-time schedule dispatches incomplete automatic I/MR/P draft sets at
00:30. The cutoff is the start of the current institution-local day, so only
complete calendar days are eligible. The policy tries the latest 14 days, then
21 and 28 days only for the predefined quantity rules. Signals never select a
more favourable period. The scheduled identity, service-row lock and database
unique identity prevent duplicate candidates. A completed automatic I/MR/P set
is not regenerated, including after review, rejection or approval.

Scheduled creation has no human actor and can only call the explicit system
entry point with policy version `phase1-automatic-v1`. It always writes sealed
`draft` records; review, approval, rejection and retirement continue to require
the existing permission and audit trail. No UI action accepts a service, period,
cutoff, chart family or subgroup selection. Existing frozen samples and lifecycle
records are not changed.

Operations must keep the Laravel scheduler and ordinary queue worker running.
Each run dispatches at most five service jobs to the `baseline-preparation`
queue. A dedicated single `baseline_queue` worker is capped at 0.5 CPU and
256 MiB, so baseline jobs never occupy the regular monitoring worker; each job
has a 55-second timeout below its worker's 60-second timeout and the database
queue's 90-second retry lease. Both workers must share the application's
database and unique-job cache. A missed run is safe to repeat: incomplete
services are redispatched, automatic identities deduplicate the result, and
existing lifecycle decisions are retained. If only part of an older set exists,
only missing chart families are prepared and they reuse the original group ID.
The schedule does not approve or activate a reference.

## Historical coverage interpretation

The exploratory Performance Analysis and the fixed 28-day SLI overview use
different windows. Their coverage percentages therefore need not match. Both
legacy calculations exclude manual/diagnostic observations, subtract recorded
maintenance, collapse duplicate checks into one estimated interval, use
half-open UTC windows derived from the institution calendar, and count missing
estimated intervals as missing rather than successful.

The exploratory calculator truncates its effective denominator at the first
recorded automatic observation, whereas the fixed 28-day SLI overview starts at
the later of the requested window and `monitored_services.created_at`. This
pre-existing lower-bound difference, as well as the different selected windows,
can produce close but non-identical values such as 93.56% and 93.50%. Both start
policies are now returned in calculation metadata and disclosed in the UI; no
denominator was changed by this correction.

For legacy history, expected opportunities are estimated with the service's
**current** `check_interval_minutes`. The database does not contain a complete
effective-dated history for every earlier interval/configuration edit. This
means a historical value such as 93.56% or 93.50% is not an exact reconstruction
of all nominal opportunities. The API payload and UI label this basis as
`historical_estimate_current_service_interval_v1` and disclose that limitation.
No missing record is fabricated and no statistical denominator or stored result
is rewritten.

Fixed-cadence opportunity coverage remains a separate ledger measure described
in `fixed-monitoring-cadence-v1.md`. It must not be mixed silently with legacy
estimated coverage, availability, latency compliance, or SLO evaluation.

## SLO and SPC safeguards

No SLO target is invented when no approved proposal exists. Conditional and
combined SLI values remain unavailable without an objective where required.
Calibration and independent prospective/retrospective evaluation remain
separate, approved targets remain immutable, and automatic SLO preparation
remains disabled by default.

The I/MR/P formulas and complete calculation populations are unchanged. Browser
downsampling is display-only and always retains first/last points, gaps,
extremes and every out-of-control signal. Exploratory estimated limits remain
visually and textually distinct from monitoring against approved frozen Phase I
limits.
