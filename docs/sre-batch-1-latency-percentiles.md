# SRE integration, Batch 1: architecture and read-only latency percentiles

This batch adds a reusable read-only analysis service. It adds no SLO target,
approval workflow, UI, migration, scheduled write, or SPC formula. It neither
creates a Phase I baseline nor evaluates a Phase II signal.

## Existing architecture

- `HttpServiceChecker` makes a timed GET, receives the HTTP response, reads its
  body for an optional keyword, and compares the status code with the configured
  expected code. `ApiServiceChecker` makes a timed GET or POST, decodes JSON,
  optionally checks a configured JSON path/value, and compares the status.
- Both checkers capture `checked_at` at attempt start and use a monotonic
  `hrtime` timer. They round elapsed nanoseconds to integer milliseconds at
  success, assertion failure, exception, or timeout. The measured interval
  includes client/request setup after timer start, network execution, response
  reception, and applicable keyword/JSON validation. It excludes queue wait,
  `ServiceCheckRunner` persistence, and later incident/SPC processing.
- `ServiceCheckRunner` stores `response_time_ms` unchanged from `CheckResult`;
  successful checks are classified against the current warning/critical
  thresholds. Failed attempts may still store elapsed diagnostic milliseconds,
  but are not completed-success latency observations. No historical value or
  warning/critical classification is repaired by this analysis.
- `MonitoredService` holds HTTP/API type, cadence, functional expectations,
  warning/critical response thresholds, and the existing SLA target. The
  `service_checks` table stores nullable unsigned milliseconds and has a
  `(monitored_service_id, checked_at)` index. The research model scopes exclude
  manual, synthetic, diagnostic, and maintenance-tagged checks; latency adds
  successful checks with non-null, non-negative milliseconds.
- `ReliabilityMetricCalculator` combines automatic checks, coverage, confirmed
  incidents, uptime/downtime, MTBF, and MTTR into stored metrics.
  `SlaCalculator` stores monthly availability against the existing SLA target
  and error budget. The SLA target is not a separate SLO target. Filament has
  reliability/SLA views and settings, but no SRE latency-percentile dashboard.
- Phase I freezes eligible samples and calculates I/MR and hourly P through
  `BaselinePopulation`/`BaselineStatistics`; reviewed/approved references are
  immutable. Phase II uses approved references and records monitoring points,
  evaluations, and signals. This analysis calls neither calculator.
- `ResearchPackage` exports raw checks, eligibility/exclusions, coverage,
  reliability/SLA metrics, approved baseline snapshots, and Phase II history.
  The Phase I export also includes frozen members and calculated points. This
  batch does not change those export formats.

`response_time_ms` is the duration of a **synthetic monitoring check** from
this application. It is not browser page-load time, Google load-balancer
request latency, or time to first byte (TTFB). It includes post-response
validation, and it is recorded at attempt-start time. Different clients,
network paths, validation rules, and clocks can yield different latency series.

## Fixed analysis policy

`LatencyPercentileAnalysis` uses the latest **28 complete institution-local
calendar days**. Local midnight today is the exclusive UTC end and the data
cutoff; local midnight 28 days earlier is the inclusive UTC start. A record is
eligible only if its `checked_at` lies in `[start,end)`, it was created by
cutoff, and it was not modified after cutoff. The same current recorded
maintenance exclusions used by research populations apply. HTTP and API
services are analyzed separately; no cross-service pooling occurs.

For each service, failed eligible checks and successful checks without valid
latency are counted separately. Only successful checks with a non-null,
non-negative latency enter the percentile distribution. An empty distribution
has null min/max/mean/percentiles and `no_data`. With fewer than the project's
existing exploratory minimum of 20 observations, results are calculated but
marked `insufficient_data`. This is a research-policy flag, not an ISO minimum
or an SLO target. A check with missing latency may still cover a monitoring
opportunity; a failed check covers an opportunity but never becomes a zero or
healthy latency value.

Percentiles use the exact **R type 7 linear interpolation** definition. Sort
eligible milliseconds ascending (check ID breaks ties). For probability `p`
and count `n`, let `h = 1 + (n - 1)p`, with `j=floor(h)` and `k=ceil(h)`.
The result is `x[j] + (h-j)*(x[k]-x[j])`, using one-based order statistics.
P50/P90/P95/P99 use `p=.50/.90/.95/.99`. A one-observation series returns
that observation for every percentile. No rounded input or interpolated
output is silently replaced with an integer.

Coverage uses the current service cadence, service creation time, and current
applicable maintenance windows. It subtracts maintenance from the 28-day
exposure, estimates `ceil(eligible seconds / cadence seconds)` expected slots,
and counts distinct occupied slots from eligible automatic HTTP/API checks.
It reports missing opportunities, percentage, and status separately from
sample adequacy and failures. The result is a completeness estimate, not
measured availability or a count of proven lost scheduler jobs. A service
with no checks after creation has missing opportunities, not 100% coverage.

## Performance and validity

The existing service/time index bounds all check scans to one service and
28 days. MySQL 8.4 computes `COUNT/MIN/MAX/AVG` and the `ROW_NUMBER()` order
statistics; PHP receives at most eight latency rank values. Exact ranks may
require a database sort over eligible 28-day values. Coverage reads only IDs
and timestamps in 1,000-row chunks and retains one boolean per occupied slot.
At the minimum effective one-minute cadence, normal 28-day opportunity storage
is about 40,320 slots, plus a daylight-saving-hour difference where applicable.
Repeated checks do not increase that in-memory slot map. No index or schema
change is required in this batch.

The aggregate, rank, and coverage queries for one service run in one database
transaction, so they see one MySQL repeatable-read snapshot even if monitoring
continues concurrently. The calculation is repeatable for an unchanged historical snapshot and
configuration. It is **not** an immutable historical snapshot: late edits to
checks, service cadence, maintenance history, or institution timezone can
change a later read-only result. Checks recorded/edited after the fixed cutoff
are excluded, so they do not leak future information into this run. Coverage
uses current configuration; configuration versioning and SLO policy decisions
belong to later batches. Percentiles condition on successful checks and can
look deceptively good during failures, so always interpret them with the
reported failure count, missing opportunities, and sample status.

## Existing threshold inversion to review

`ServiceCheckRunner` checks `critical_response_ms` before
`warning_response_ms`. If a service has `critical_response_ms` below
`warning_response_ms`, successful times at/above the lower critical threshold
become critical and the intended warning band is unreachable. The new analysis
reports `critical_response_below_warning_response` for such a service. It does
not update the configuration or reclassify any historical check. The project
owner confirmed an existing production inconsistency for **Service ID 1, the
main university website**: `warning_response_ms = 2500` and
`critical_response_ms = 500`. These values were reported for documentation;
this batch does not independently query production or change either threshold.
A read-only production review can use:

```sql
SELECT id, name, warning_response_ms, critical_response_ms
FROM monitored_services
WHERE check_type IN ('http', 'api')
  AND critical_response_ms < warning_response_ms;
```

## Recommended Batch 2 boundary

Define distinct availability and latency SLI denominators, explicit SLO
targets and review rules, and an error-budget interpretation before adding
thresholds or a dashboard. Decide how low coverage, changing configuration,
and failure-heavy periods affect SLO eligibility. Preserve the fixed cutoff
and keep SLO calculations separate from approved SPC reference limits.
