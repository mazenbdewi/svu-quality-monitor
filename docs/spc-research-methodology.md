# SPC research methodology — R3

Phase I Baseline is now provided separately by [R4A](spc-phase1-baseline-methodology.md). The R3 calculations documented here remain exploratory and unchanged; Phase II remains deferred.

R3 provides exploratory analysis, not approved Baseline or Phase II monitoring. It does not change Reliability, SLA, the incident state machine or R1 MonitoringCoverageCalculator. No SPC notifications or baseline tables are introduced.

## Research core and limits

I charts use individual successful response durations; MR charts use absolute differences between adjacent eligible durations. P charts use the proportion of problematic observed checks within a time subgroup. The existing I formula uses mean ± 3 MR-bar/1.128 (lower bound clipped to zero for durations); MR uses MR-bar, UCL 3.267 MR-bar and LCL zero. These formulas are retained. See [NIST individual charts](https://www.itl.nist.gov/div898/handbook/pmc/section3/pmc322.htm).

P uses a binary outcome per observed check: `problematic = !is_success || is_slow`. For subgroup i, n_i = observed eligible checks, d_i = problematic checks, p_i = d_i/n_i. The pooled center is sum(d_i)/sum(n_i), not an unweighted mean of subgroup proportions. UCL_i = min(1, p_bar + 3 sqrt(p_bar(1-p_bar)/n_i)); LCL_i = max(0, p_bar - 3 sqrt(p_bar(1-p_bar)/n_i)). Each point is judged against its own limits before rounding to four decimals. Chart-level P UCL/LCL are null because variable subgroup sizes do not have one common limit. See [NIST proportions charts](https://www.itl.nist.gov/div898/handbook/pmc/section3/pmc332.htm).

C/U remain Additional / Legacy Analysis. Their existing counts concern problematic checks rather than an independently justified defects/opportunities process; R3 does not promote these to primary research charts or expand their formulas. Their historical populations are retained. Their new results are marked legacy; old results remain untouched.

All new core limits are **Estimated From Analysis Window**. These same-window estimates are exploratory: absence of a limit exceedance is not proof of stability, normality, SLA compliance or an approved monitoring baseline. Serial dependence, changing traffic/case mix and performance thresholds can invalidate simple assumptions. P's binomial approximation and I/MR's variation estimates require substantive review; R3 does not test those assumptions or correct autocorrelation/overdispersion. Hourly aggregation is a configurable starting comparison, not a scientifically optimal choice.

## Shared eligibility and ordering

`SpcResearchData` is used by calculator and Minitab research sheets and calls the R1 `researchChecks` / `researchResponseTimes` scopes. Both populations require automatic HTTP/API checks, non-maintenance, non-synthetic and non-diagnostic. Applicable maintenance windows also exclude retrospectively flagged time consistently from calculation and export.

I/MR additionally require functional success and a non-null, nonnegative response duration. Successful warning/critical checks remain latency observations. Functional failures and timeouts remain stored, but do not become ordinary latency measurements. P includes eligible failures and treats successful warning/critical checks as problematic. Latency interpretation is conditional on functional success: always report P alongside it to avoid concealing failure behavior.

Checks sort by `checked_at`, then `id`. MR uses only successive eligible checks **inside** the requested window; no pre-window measurement is borrowed. It may bridge an excluded check or time gap, since adjacency is within the eligible sample; stored current/previous check IDs make this auditable. R4 will revisit monitoring-boundary rules.

## Five independent time concepts

| Concept | Meaning in this implementation |
| --- | --- |
| Collection frequency | Existing service check interval, unchanged |
| Calculation frequency | Existing daily SPC command, at 00:25 in analysis timezone |
| Aggregation interval | Raw check for I/MR; hourly (default) or daily for P |
| Analysis window | 7, 30 (research default), 90 completed calendar days, or custom |
| Reporting period | Export selection; not a baseline or forced aggregation interval |

`monitoring.spc.analysis_timezone`, configurable with `SPC_ANALYSIS_TIMEZONE`, defaults to `Asia/Damascus`. UTC storage is unchanged. Calendar boundaries are constructed in this timezone, then converted to UTC. All research queries use `[start,end)`. Date-only custom end is an **exclusive** local midnight; to include September 30, use October 1 as end.

Without `--date`, daily/weekly/monthly commands select the previous completed calendar period. At September 17 00:25 Damascus, daily means September 16 local, or September 15 21:00 UTC through September 16 21:00 UTC. The schedule creates operational daily analyses, not a 30-day research result. No extra jobs were added; scheduling a 30-day research window remains a future option.

Manual research analysis is available through the Control Charts action and `ControlChartCalculator::analyze(service, type, window='30', from=null, to=null, aggregation='hourly')`. It reads automatic checks; it is not a manual check. Presets end at today's local midnight. Custom end can include the current day. The calculation cutoff is min(requested end, now, optional explicit cutoff); a cutoff before requested end is persisted and displayed as partial. Only checks strictly before cutoff enter the calculation. Requested period boundaries are retained.

Unlike R2's conservative last-observation cutoff, SPC cutoff is the **selection horizon**, not a claim of continuous observation; missing time through that horizon remains visible. `calculated_at` is processing time. Late backfills, edited flags or changed maintenance/configuration can alter a later recalculation: this is not a full as-known-at-the-time snapshot, nor does a cutoff alone eliminate every form of look-ahead bias.

## Subgroups, missing observations and coverage

Every elapsed hourly/daily bucket intersecting `[start,cutoff)` is represented in `research_context.buckets`, including empty buckets. First and last bucket boundaries are clipped to the window. Future buckets beyond cutoff are not treated as missed observations.

Expected opportunities are computed from the service's current check interval and bucket exposure after creation and merged maintenance exclusions. Slots follow the R1 occupied-slot principle on the remaining time axis: repeated checks within one slot cannot fill a missing slot. Raw I/MR use hourly diagnostic coverage buckets regardless of the P aggregation option; their plotted measurements remain individual. SPC uses its HTTP/API research population, so this is **research subgroup coverage**, not a change to R1's all-service operational coverage. It does not infer a historical monitoring activation date; pre-creation and maintenance exposure is excluded, while empty elapsed opportunities are retained as missing. A partial final slot counts as one expected opportunity.

Each bucket stores start/end, expected_count, observed_count, covered_count, missing_count, coverage percent, problematic_count, explicit functional failed_count, problematic_proportion and status:

- `observed`: all expected slots occupied.
- `partially_observed`: samples exist but some slots are empty.
- `missing`: expected observations but none observed; p is null, never zero.
- `not_expected`: no observed samples and no eligible exposure (for example full maintenance).

Missing count = expected slots minus occupied slots, not necessarily expected minus raw record count. Missing observations are never added to n_i. Empty groups produce no numeric ControlChartPoint, but remain in metadata and bucket export. The graph inserts null gaps and does not connect across them. Partially observed groups retain their actual n_i and point-specific limits. These are exploratory incomplete samples, not estimates of missing outcomes.

## Sufficiency policy

Core results persist `no_data`, `insufficient`, `preliminary` or `analyzable`:

- No eligible input observations: no_data.
- Fewer than two computable points: insufficient (including one I point or unavailable MR).
- Otherwise fewer than the configured exploratory minimum, or missing expected slots: preliminary.
- Otherwise analyzable **for exploratory review only**.

`monitoring.spc.exploratory_min_points` defaults to 20 as an explicit project reporting policy, not a statistical law or approved baseline size. It is saved with results for traceability. Analyzable does not certify distributional assumptions, representative subgroups, independence or stability. Degenerate limits and rare outcomes still require scientific review.

## Identity, persistence and legacy compatibility

A nullable unique SHA-256 analysis identity encodes service, chart type, UTC start/end, aggregation (`raw` for I/MR), analysis mode and timezone. Hourly/daily P and different windows coexist. Reporting label is not part of the scientific identity. New core mode is `exploratory`; only R4 may implement baseline/monitoring modes. No such mode is currently accepted through the API.

`calculation_version = spc-r3-v1`, timezone, aggregation, mode, cutoff and JSON context are saved. Individual points record source IDs; P points record subgroup context. Chart save, point deletion and replacement occur in one database transaction. A point-write failure rolls back both metadata and previous points. Recalculation of the same identity replaces that snapshot; immutable versions are deferred.

The new migration only adds nullable context fields and replaces the old inadequate uniqueness constraint. Existing rows keep null context/identity and are displayed as legacy; they are neither backfilled nor automatically recalculated. Rollback drops the new fields but deliberately does not recreate the old unique constraint, which cannot represent coexisting valid analyses. Reverting application/schema after generating R3 data requires a reviewed compatibility plan; no rows are deleted to force the old constraint.

## Minitab and reports

The Raw Checks sheet now exports the eligible P research population using the shared service. Existing numeric flags are retained, with check ID, source, type, performance status, maintenance flag, research eligibility, latency eligibility and latency exclusion reason appended. Filter `latency_eligible=1` for exactly the I/MR population. Excluded manual/DNS/synthetic observations remain in the original operational database, not in the research sheet.

Bucketed Checks is a research schema: bucket_start/end, expected/observed/missing counts, coverage, problematic_count/proportion, separate functional failed_count, status, timezone and aggregation. It uses the **same** bucket implementation as P. This intentionally replaces the old ambiguous bucket export schema; external scripts must use column names. Supply matching service, `analysis_start`, `analysis_end`, optional `data_cutoff`, and `bucket_size` for parity. Explicit timestamps can include UTC offsets. Date filters represent inclusive local dates, converted to an exclusive next-midnight end. Without dates, research export defaults to the last 30 completed days.

All Chart Points is an additional sheet exporting every point of selected overlapping charts (not just signals), with chart ID, period, timezone, aggregation, mode, version, cutoff, time/value/n, CL/UCL/LCL and signal flag plus source/subgroup context. It exports stored point-specific limits. A chart_id filter selects that chart without applying a default date window. Control Chart Summary includes compact context; Stored Chart Buckets exports persisted subgroups, including missing groups for charts without points, one per row. This avoids putting a 30/90-day bucket history into a single oversized spreadsheet JSON cell. Existing out-of-control and Reliability sheets remain available. Historical legacy rows have explicitly unknown context rather than invented R3 settings.

The UI keeps its current layout, exposes the window/aggregation action and result context, replaces categorical stable/normal findings with exploratory sufficiency and limit-exceedance wording, distinguishes C/U legacy labels, and displays P point-specific limits and d_i/n_i. Graph axes use the stored analysis timezone; exported instants are UTC or offset-bearing ISO timestamps.

## Validation and R4 boundary

Tests exercise local schedule boundaries, 7/30/90/custom windows, partial cutoff, R1 exclusions, outcomes including timeout/critical, deterministic MR, half-open endpoints, missing/partial buckets, variable n and clamped P limits, identity coexistence, legacy preservation, transaction rollback and raw/bucket/all-point export parity. Full suite retains R1/R2, SLA and incident regressions.

R4: approved stable baseline selection, baseline/monitoring separation, fixed Phase II limits, reviewed MR boundary treatment, versioned/frozen study inputs and richer assumptions review. None is implemented in R3; there are no SPC notifications.
