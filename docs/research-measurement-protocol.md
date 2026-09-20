# Research Measurement Protocol — R1

Status: R1 measurement foundations (historical scope of this document); not approval of existing Reliability/SPC results as a final research sample. No historical checks or charts are rewritten. R2 Reliability is now implemented as specified in [reliability-methodology.md](reliability-methodology.md), which supersedes the R1-only Reliability statements below. R3 SPC is now implemented as [exploratory research foundation](spc-research-methodology.md), which supersedes the R1-only SPC statements below; approved Baseline/Phase II is deferred to R4.

## Measurement dictionary

| Term | R1 definition |
| --- | --- |
| Check Success | `is_success=true`: the configured functional assertion passed. HTTP/API require expected status and any configured content/JSON assertion. It does not guarantee acceptable latency. |
| Check Failure | `is_success=false`: functional assertion or transport failed. A failed observation is not automatically a confirmed incident. |
| Healthy Performance | Successful timed response below `warning_response_ms`. |
| Warning Performance | Successful timed response at/above warning and below critical threshold. |
| Critical Performance | Successful timed response at/above `critical_response_ms`; still functionally successful, not an outage. |
| Slow / Performance Degradation | Legacy field `is_slow=true` means successful timed performance classified warning **or critical**. Failed attempts have `is_slow=false`; their elapsed time is diagnostic. SSL expiry warnings without response time are not latency degradation. |
| Problematic Check | `!is_success || is_slow`, exposed as `ServiceCheck::is_problematic`. For newly collected HTTP/API data: functional failure OR successful warning OR successful critical performance. |
| Confirmed Incident | Functional failure sequence reaching configured `failure_confirmation_count`. `started_at` is first failure; `confirmed_at` is confirming check time. Slow/critical performance alone never opens an incident. |
| Recovery | Configured consecutive successes after an incident. On confirmation, end time is backdated to the first success in that recovery sequence. A slow successful response still counts as functional success. |
| Planned Maintenance | Applicable maintenance window at attempt time, with half-open start/end semantics; persisted as `is_during_maintenance` and `maintenance_window_id`. |
| Unplanned Downtime | Incident time outside planned maintenance under the chosen calculation policy. R1 does not repair the differing Reliability/SLA implementations; final research interpretation awaits R2. |
| Availability | Fraction of eligible observed time without unplanned downtime under an explicit policy. Existing calculators are unchanged and do not yet account for coverage. Never infer demonstrated 100% availability from absent incidents alone. |
| Monitoring Coverage | Completeness of automatic monitoring opportunities over the measurable period; independent of check success and service availability. |
| Missing Observation | An eligible monitoring interval containing no automatic observation. Unknown, neither success nor failure; no synthetic failed check is inserted. |
| Eligible Research Check | Automatic HTTP/API observation, not marked synthetic/diagnostic, outside recorded planned maintenance. Binary outcomes include functional failures. Completed-latency sample additionally requires success and non-null, non-negative response time. |

Thresholds and functional expectations are part of the measurement protocol and must remain fixed within a study stage. Failure, degradation, critical performance and outage are distinct concepts.

## Central query contract

`ServiceCheck::query()->automaticObservations()` selects source `automatic` and rejects metadata flags `is_synthetic=true` or `is_diagnostic=true` (JSON booleans). Missing flags mean ordinary production observation. Fixture/import writers must explicitly mark artificial or diagnostic rows, or keep them outside the research database; R1 cannot infer unmarked synthetic data.

`researchChecks()` adds HTTP/API and `is_during_maintenance=false` for binary outcome research. `researchResponseTimes()` adds functional success and available non-negative milliseconds. Scope results can then be filtered by service and a half-open `[start, end)` period. DNS/SSL/TCP are operational context, never pooled with HTTP/API latency.

These are the shared research entry points. Existing SPC calculators, Minitab exports, dashboards and Reliability populations are **not switched** in R1: that coordinated integration belongs to R2/R3. Existing exports are not certified research samples. New critical checks flow through existing problematic calculations via the corrected flag, without formula changes or backfill.

## Automatic/manual and maintenance

Manual checks remain stored and operationally useful. They are excluded from research scopes and coverage. They still affect the operational incident state machine and can postpone scheduled checks because scheduling currently considers the latest check of any source. Incident-derived Reliability therefore requires R2 review before final research use.

The runner remains the authoritative point-in-time maintenance tagger via `MaintenanceWindowService`. Research scopes use the stored flag; retroactive window edits do not retag history. Coverage subtracts current merged maintenance intervals and also rejects tagged maintenance observations. Freeze maintenance definitions within the study stage; retrospective corrections require an explicitly documented new analysis. Coverage is not an immutable historical snapshot.

## Coverage calculation

Read-only API: `MonitoringCoverageCalculator::calculate(service, from, to)`; it does not store records, change incidents, or report health/availability.

- Requested interval must be positive; service interval must be at least one minute.
- UTC interval is `[from, min(to, now))`.
- Effective start is the later of requested start and first actual automatic, non-synthetic/non-diagnostic observation before cutoff. No monitoring history means unknown start, expected=0, coverage=null and `no_data`; it does **not** mean no monitoring was required. The unmeasured prefix is outside the estimate, not deemed healthy.
- Remove merged applicable planned-maintenance intervals. All automatic service types can establish coverage; outcome success is irrelevant.
- Let `D` be remaining eligible seconds, `I=check_interval_minutes*60`, and `E=ceil(D/I)`.
- Concatenate eligible time segments into an active-time axis starting at effective start. Each observation occupies interval `floor(active_elapsed_seconds/I)`; one interval can contribute at most one covered opportunity.
- `O` = actual eligible automatic records; `C` = distinct occupied intervals; `missing=max(0,E-C)`; `coverage=100*C/E` if E>0, otherwise null.
- Return both O and C. Repeated observations in a burst cannot compensate for an empty interval. Failed checks count towards coverage, but never towards healthy performance merely because they were observed.

Tolerance is interval-wide, not a deadline at a particular second. There is no extra grace interval and no assertion of exact scheduling. A partial final interval counts as one opportunity; queue delay across a boundary can reduce coverage. This deliberately conservative estimate is not a scheduler SLA. Review actual gaps alongside it; do not interpret `missing_checks` as a proven number of lost queue jobs.

Coverage uses the current interval and current maintenance windows, not historical configuration versions. Evaluate only fixed-configuration study stages. It cannot reconstruct activation history or explain a missing observation. Future time is excluded and intervals before the first observation are explicitly not covered by the estimate.

## Data sufficiency states

- `no_data`: no automatic history, zero eligible exposure, or no eligible observations in the requested measurable interval. Coverage is null when E=0, otherwise 0.
- `insufficient_coverage`: some occupied intervals but C<E.
- `observed`: E>0 and C=E. Means fully covered under this interval estimator, **not healthy, stable or statistically sufficient**.

R1 uses no arbitrary 80/95% health threshold. Existing dashboards and Availability calculations remain legacy consumers; these new semantics must gate research interpretation in R2/R3. R1 does not claim that all old dashboard labels are corrected.

## Timeouts and timestamps

`MeasurementLimits` centralizes job timeout (30 seconds) and maximum service timeout (job minus a ten-second persistence/incident-processing reserve = 20 seconds). The form rejects values outside 1–20; `MonitoredService::timeoutSeconds()` also clamps old or programmatic settings to this range without rewriting stored configuration. Default remains 10 seconds.

Docker worker currently uses 60 seconds; database retry-after defaults to 90 seconds. Regression checks enforce service < job <= configured Docker worker < database retry-after. Deployment changes must retain these inequalities, with retry-after exceeding execution timeout; alternative worker supervisors must apply the same contract. The reserve is not a guarantee against arbitrary database/network stalls. Reducing an existing effective timeout above 20 seconds changes measurement behavior and starts a new study stage.

HTTP/API `checked_at` means **attempt start**, captured before the network request. Elapsed milliseconds use a monotonic clock. Completion, exception and timeout records retain the start timestamp. UTC storage remains unchanged. Other check types have different timing semantics and are not part of the latency sample.

HTTP/API failures retain `response_time_ms` as elapsed attempt duration, including timeouts and exceptions. R1's research latency scope excludes all functional failures (including completed HTTP error responses); this explicitly measures latency conditional on functional success. The binary research scope retains failures regardless of elapsed time. Report failure proportion alongside latency to avoid survivor bias. No raw values are deleted or imputed. Existing I/MR populations are unchanged until R3 adopts this policy deliberately.

## Configuration and audit

Endpoint, interval, warning/critical thresholds and check type changes end the current measurement stage and begin a new one. Administrative audit records before/after for interval, thresholds and type; endpoint changes now carry explicit `endpoint_changed` without exposing URLs or credentials. Sensitive configuration changes remain markers. Audit applies to administrative workflows, not arbitrary SQL/model writes; study changes must use audited workflows.

No complete configuration versioning is added in R1. Preserve approved settings and dataset extracts for each stage. Full reproducible calculation versions and Baseline history belong to later work.

## Explicitly deferred

R2: incident counting, completed/censored MTTR, overlapping downtime, future time, actual observation start, manual-check influence, coverage integration into Reliability/operational availability, and reconciliation with SLA/SLO definitions.

R3: research population integration into SPC and Minitab, minimum-data gates, analysis windows, Phase I/II, immutable Baseline, historical snapshots, limit formulas/policies, and signal timing. No Baseline, Phase II or formula change ships in R1.

Operational availability describes the observed service experience under its stated exclusions. SLA/SLO availability measures compliance under its own period/target/exclusion policy. Monitoring coverage describes how well either can be observed; it is neither of those availability values. `SlaCalculator` is unchanged.
