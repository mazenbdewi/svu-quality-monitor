# Reliability methodology — R2

This is the implemented retrospective, incident-based observation policy. It does not certify uninterrupted service between samples, change the incident state machine, or implement SPC Baseline. All intervals are half-open `[start, end)`; instants are stored/calculated in UTC. Calendar boundaries come from the command's application timezone and are converted to UTC.

## Observation and coverage

Let P0/P1 be requested boundaries, C the optional caller cutoff, F the first eligible automatic observation since service creation, and L the last such observation at or before Q:

- Q = min(P1, now, C when supplied).
- S = max(P0, service.created_at, F).
- E = min(Q, L).
- No observation, E <= S, no eligible seconds, or no eligible samples means `no_data`.

Automatic observations exclude synthetic/diagnostic records and persisted maintenance checks. Samples additionally exclude applicable maintenance windows. Reliability accepts all automatic service types; R1's HTTP/API restriction concerns the response-time research population, not service uptime. Manual observations never establish F/L or enter the sample ratios.

The last observation is a conservative cutoff: we do not extend its state by a sampling interval. An observation exactly at E establishes that cutoff but is not a sample in `[S,E)`; it belongs to the following interval. A completed historical day without an observation exactly at midnight may consequently end before midnight. This is deliberate and visible in `measurement_context`, not an assumption of healthy remaining time.

Coverage uses the R1 occupied-slot policy through **Q**, not merely E, so clipping to stale data cannot hide the missing tail. Its start respects service creation and first observation. Maintenance is removed from the time axis. Expected slots = ceil(eligible requested seconds / configured check interval); repeated checks in a slot cannot fill another slot. Failures count as observations. Full occupied-slot coverage gives `observed`; partial coverage gives `insufficient_coverage`; no observations gives `no_data`. There is no arbitrary percentage threshold. Missing means unknown, never success or failure. Coverage is an interval-based estimate, not proof of continuous observation.

## Time, events and formulas

Let M be the union of applicable maintenance intervals, O = `[S,E)` minus M, T = seconds(O). Only incidents with `confirmed_at != null` qualify. Let I be the union of their intervals clipped to `[S,E)`. Then:

- D = seconds(I minus M), U = T - D.
- Incident-based observed availability = 100 U / T, only when status is `observed`.
- N = number of confirmed incident **starts** in `[S,E)` outside maintenance.
- MTBF minutes = (U / 60) / N, only with observed coverage, N > 0 and U > 0.
- Failure rate per uptime minute = N / (U / 60), only with observed coverage and U > 0.

We use `started_at` (first failure) rather than confirmation time so the confirmation delay does not move onset to another period. A multi-day incident contributes downtime in multiple periods but only one new failure. An incident starting during maintenance contributes any later unplanned downtime but is not counted as a new unplanned start. Distinct source incidents remain distinct failure events; their overlapping durations are unioned, never summed twice.

With no observed failures MTBF is null, not an invented finite lifetime; failure rate is zero if uptime is positive. Both are null when uptime is zero. Failure rate and MTBF are reciprocals before display rounding when both estimable, not independent evidence. These are exposure-based descriptive estimates, not proof of a constant hazard or a prediction of future reliability.

All calculations use seconds before rounding. Exact whole seconds are retained in measurement_context. Legacy integer-minute columns are rounded presentation values and must not be used to reconstruct availability.

## Completed repairs and open incidents

MTTR uses the completion cohort: confirmed closed incidents whose `ended_at` lies in `[S,E)`. Each contributes its **full** start-to-end duration minus maintenance over that entire repair, including time before S. Mean seconds / 60 is MTTR. Zero unplanned-duration repairs are excluded. An incident starting before the period and ending within it belongs to this cohort; one ending later does not. An end exactly at a boundary belongs to the following period. This deliberately differs from averaging daily clipped downtime fragments. It is elapsed unplanned restoration time, not recorded technician labor.

An open incident contributes downtime only through E and is excluded from completed MTTR. `open_incident_count` counts incidents still ongoing immediately before the exclusive end, including an incident known today to have ended at or after E. No future duration is included. MTTR is null without completed repairs or sufficient observation coverage. Coverage of the completion period does not independently certify every earlier hour of a repair that began before it; retain the source incident history when interpreting that cohort.

## Ratios and interpretation

For automatic non-maintenance samples in `[S,E)`:

- Successful check ratio = count(is_success) / observed sample count.
- Acceptable performance ratio = count(!is_problematic) / observed sample count.
- `problematic = !is_success || is_slow` from R1.

A successful warning/critical observation lowers performance conformity, not functional success. It does not itself cause downtime. Downtime requires a confirmed functional incident under the existing state machine. Ratios are fractions from 0 to 1 and describe actual samples even when coverage is insufficient; they do not fill missing samples. Both are null without samples. For compatibility, legacy `successful_checks` continues to mean acceptable/non-problematic checks; `functional_success_count` provides the unambiguous functional count.

Availability, MTBF, MTTR and failure rate are null for `no_data` or `insufficient_coverage`. UI availability shows “لا توجد بيانات كافية” instead of an invented percentage. Stored component durations remain available for audit but do not assert complete coverage.

## Manual observations and retrospective history

Manual checks can still open/confirm/close operational incidents and postpone automatic scheduling, because R2 does not change the state machine or scheduler's source selection. Thus incident-derived results are not a fully automatic-only experimental event series, even though direct samples and coverage are automatic only. A later small extension should preserve event provenance (trigger check IDs/source) and derive a separate research incident view under a frozen policy; do not infer provenance from the current aggregate incident alone.

Historical calculations use historical incident/maintenance intervals and observations at or before their cutoff. Moving today's clock cannot extend a completed historical period. This is retrospective confirmed history, **not** an as-known-at-the-time reconstruction: a later confirmation, corrected incident, deleted/backfilled check, changed maintenance schedule or changed check interval can change a recalculation. Current maintenance records and service configuration are not immutable historical versions.

## SLA comparison

SlaCalculator is unchanged. Both calculators clip confirmed incident intervals, union overlaps, and subtract merged maintenance. Tests compare exact eligible seconds, downtime and availability when observation boundaries coincide. SLA starts at requested/service creation time, ends at min(requested end, now), and applies the configured target and error budget. It does not gate results on R1 coverage or first/last automatic observation. Therefore no-data results and exposure denominators can intentionally differ. SLA compliance is not research evidence of observed availability. Matching calendar labels alone does not guarantee matching exposure boundaries.

## Storage, summaries and exports

A small migration adds nullable JSON `measurement_context` and permits null `availability_percent`. This context is necessary to preserve eligibility, actual cutoff, precise seconds, coverage, incident cohorts and separate ratios. No existing row is backfilled or reinterpreted. Migration rollback removes context but keeps availability nullable rather than fabricate values. The migration must be deployed before running the new calculator; development verification runs it only in the isolated test database.

Requested period identity is retained. `updateOrCreate` replaces a matching snapshot on recalculation; it is not immutable research versioning. Old inclusive-end rows have different keys from new exclusive-end rows and can coexist. Old rows without context remain legacy/unverified and are excluded from weighted research summaries. Snapshot versioning, frozen input/configuration history and an immutable final study export remain deferred.

Institutional summaries use **weighted observed availability** = 100 sum(U) / sum(T), using only observed contextualized snapshots. This is service-time weighting, not end-to-end system availability. Unknown services are excluded, not assumed healthy; interpret the result as the covered subset. Overlapping snapshots for the same service cause a null summary instead of double counting daily/weekly/custom periods. Non-overlapping periods and services can be combined.

Reliability and Minitab reliability exports append the JSON context without shifting existing columns. The context preserves the boundaries and units needed for interpretation; consumers should parse it and filter observed records. It does not make older exports immutable or certify SPC exports. PDF/Excel summaries use the weighted calculation.

## Scheduling and validation

The existing scheduler still calculates yesterday at 00:10. Daily/weekly/monthly command periods end at the exclusive next boundary. Manual current-day calculation keeps its requested full day but persists and displays the actual cutoff.

`ReliabilityTimeSemanticsTest` covers creation/first observation, explicit and future cutoffs, stable historical time, full repair cohorts, three-day onset counting, overlap unions, maintenance, unconfirmed incidents, open incidents, midnight/month/year boundaries, missing/stale observations, independent ratios, subminute precision, weighted summaries, UI no-data rendering and yesterday's command. Existing Reliability, SLA, maintenance and monitoring tests provide regression coverage.

R3/R4 remain separate work: SPC Baseline/Monitoring, SPC research population integration, immutable research snapshots/configuration provenance, and research event provenance. No R3 implementation is included here.
