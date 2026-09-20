# Research Pilot Protocol — R5 approved research decisions

Status: research decisions below approved by the researcher on 2026-09-20; **READY TO FREEZE FOR PILOT; Pilot NOT STARTED**. This documentation approval does not execute configuration changes, authorize deployment or start collection. Record individual service permissions and the remaining operational details before activation. Preserve these approved decisions and a configuration snapshot with the exact application image/source before collection. No automatic transition from Pilot to Phase I.

## Purpose

The approved pilot lasts seven complete days (168 hours, with explicit UTC start/end) and assesses coverage, missingness, queue/detection delay, service stability, response-time distribution, serial correlation/seasonality indications, warning frequency, resource impact and export reproducibility. Seven days is not a sufficient formal Phase I baseline by definition. Its purpose is measurement and data-quality validation, not final thesis inference. It does not establish predictive accuracy or prove preventive benefit.

## Frozen research decisions

These items are resolved by the researcher's approval; they are no longer pending choices.

| Decision | Approved Pilot policy |
|---|---|
| Duration | Seven complete days. |
| Services | 4–6 authorized HTTP/API services representing different functions. Individual IDs and permissions must be recorded before activation. |
| Monitoring interval | Five minutes. Verify each selected service's deployed configuration matches this policy. |
| Research sample | Automatic checks only, subject to the existing eligibility policy. Manual checks remain operational and are excluded from the direct research sample. |
| Planned maintenance | Record it and exclude it according to the existing research policy. |
| Timezones | Storage UTC; analysis Asia/Damascus. |
| Research Core SPC | I / MR / P; P aggregation is hourly during Pilot. |
| Signal episode gap | 30 minutes. |
| Incident association horizon | 60 minutes. |
| Monitoring coverage target | 95%, explicitly an operational Pilot acceptance policy, NOT a universal statistical/SPC rule. Existing coverage denominators, exclusions and P subgroup eligibility remain unchanged. |
| Raw data retention | Preserve raw research data throughout the study; no automatic deletion of Pilot raw observations. |
| Major configuration changes | Log the change and treat it as a measurement-phase boundary. |
| Manual operational intervention | Allowed when necessary; record timestamp, reason and action. Review the affected measurement segment because operational effects may extend beyond the excluded manual check. |
| Synthetic scenarios | No synthetic failures against real university services; synthetic scenarios only in isolated test environments. |
| End of Pilot | Must not automatically create or approve a Phase I Baseline. |
| Purpose | Measurement/data-quality validation, not final thesis inference. |

## Research Decisions To Confirm

The policies above are resolved. Only the following service-specific and operational details remain before activation; they do not reopen the approved duration, cadence, retention or coverage target.

| Remaining detail | Required record |
|---|---|
| Service roster and settings | Exact 4–6 service IDs, owners and monitoring permissions; request semantics, expected response, warning/critical thresholds and rationale, timeout and confirmation counts. |
| Calendar | Actual UTC start and end spanning seven complete days. |
| Preliminary Phase II reference | Whether any reviewed/approved pilot reference will be used, and its rationale/IDs. Without one, record no_approved_baseline; formal Phase I is a later decision. |
| Custody and operations | Named custodian, archive locations, backup cadence and verified restore evidence; resource/queue-delay review limits and response procedure for gaps or coverage below the approved 95% target. |

## Service selection checklist

For each selected service record: stable endpoint fingerprint; written monitoring permission; representative business function; repeatable non-destructive request; known expected status/content; suitable latency measurement; allowed frequency; warning/critical thresholds and rationale; timeout; failure/recovery confirmation counts; owner; freeze timestamp. Do not export endpoint credentials, request headers, tokens, passwords or expected private keywords. Do not deliberately induce failures on real services. Synthetic checks/incidents belong in the isolated test environment only.

## Measurement

Application and database storage: UTC. Analysis calendar: `SPC_ANALYSIS_TIMEZONE`, frozen to Asia/Damascus for Pilot. Daily exploratory SPC uses the analysis timezone; other scheduled daily tasks use application UTC unless explicitly configured. Preserve this difference in the snapshot.

Only automatic HTTP/API checks that are not synthetic/diagnostic and not during maintenance enter the binary research sample. Manual checks remain operational records and are excluded from the direct research sample. Necessary manual intervention is allowed; record its UTC timestamp, reason and action. Planned maintenance must be recorded and excluded under the existing research policy. DNS/SSL/TCP are not latency research samples. Latency additionally requires functional success and a non-null nonnegative duration. A critical successful response is a performance issue, not an outage.

`problematic = !is_success || is_slow`. R1 sets is_slow for successful warning or critical performance with measured duration. Functional failures remain governed by the unchanged Incident State Machine. Freeze warning/critical thresholds, request semantics, timeout and confirmation counts.

Coverage is occupied expected automatic collection slots after maintenance exclusions. Duplicate records do not fill missing slots. No data means unknown, never healthy/success. R1 coverage is an operational automatic-observation estimate; R3/P research populations additionally restrict HTTP/API eligibility. Coverage uses configured cadence and must be segmented at configuration changes; an export-time setting is not proof of historical configuration.

## Reliability and SLA

R2 uses half-open UTC observation intervals, clipped by available automatic observations and maintenance. Availability uses eligible time minus confirmed incident downtime; missing coverage is disclosed and unknown/insufficient periods are not interpreted as complete healthy exposure. MTBF uses uptime per incident start; MTTR uses completed repair durations according to R2's completion cohort. Exact seconds are in measurement_context; legacy rounded minutes are presentation fields. Functional-success and acceptable-performance ratios are distinct.

SLA remains its existing operational calculation with persisted period/target/exposure/error-budget context. Do not merge operational SLA and research coverage denominators without explaining their different semantics. Neither computation is changed by R5. R2/SLA algorithm identification relies on the code/source freeze; not every row has its own version field.

## SPC reference and monitoring

Research Core is I/MR/P. Exploratory SPC is a separate retrospective analysis and is not silently used as Phase II evidence. Approved Phase I membership, safe configuration fingerprint and parameters are immutable. Review sufficiency, stability and assumptions before approval. No automatic outlier deletion or approval.

Phase II uses approved, temporally effective, compatible references. A mismatch blocks research signals; restoring settings alone does not resume a latched reference. Review and a new version are required. Preserve old reference evidence. Do not mix configuration regimes.

I compares raw successful latency with fixed CL/UCL/LCL. MR starts at the second eligible Phase II observation, uses a frozen previous value, does not borrow Phase I, and archives out-of-order arrivals without rewriting pairs. During Pilot P uses hourly aggregation and evaluates only closed full calendar groups, with fixed baseline p0 and point-specific n-dependent limits. Missing/insufficient groups do not issue final signals; their first finalized live outcome is preserved despite late arrivals.

Rule v1: strict point outside control limits (`point-outside-limit-v1`, `beyond_3sigma_limit`). No trend/run rule suite. Raw signals are deduplicated and preserved. Episodes group same baseline/service/chart/direction/mode/rule using the stored detection gap. The approved episode gap is 30 minutes and the association horizon is 60 minutes; these are frozen Pilot design choices, not optimal statistical constants.

## Timing and outcome evaluation

Observed time is measurement time (P: group start); live detected time is evaluator time after obtaining the research baseline lock; R5 avoids holding the operational service-row lock. A delayed evaluator is never backdated. Each new queued Phase II evaluation records enqueue time, job start and queue delay inside existing context; source created_at (row insertion timestamp, not a dedicated transaction-commit timestamp) and point/evaluation linkage allow recorded-to-start and persisted-to-detection delay to be inspected. Old rows lacking timing are unknown, not zero. Scheduler-triggered and catch-up jobs are distinguishable by their job context; direct evaluator calls have no queue timing.

Associate existing episodes with independently confirmed incidents only after detection. Same service, incident start strictly later than first detection and at most the frozen horizon away. Exclude maintenance and insufficient actual monitoring exposure. Earliest eligible episode is primary; nearest is retained as comparator. Lead time uses incident.started_at, never confirmation delay.

Recall = distinct eligible incidents preceded by eligible episode / eligible incidents. Precision = mature eligible episodes followed by an eligible incident / mature eligible episodes. Show denominators and chart/baseline breakdown. Incomplete follow-up is censored, not unmatched. Unmatched warnings are not intrinsically false alarms. Any-SPC recall deduplicates incident IDs. These are association metrics, not prediction accuracy.

Live and retrospective use separate identities, datasets and exports. Retrospective clock simulation cannot establish actual prior warning. Reports and packages preserve their cutoffs. Operational raw warning/critical status plus source persistence time allow fixed-threshold comparison even when no baseline exists; archive the raw history before pruning. Source persistence is not proof of notification delivery. Preserve initial snapshots; later editing of operational rows cannot retroactively recreate what was known.

## Freeze, retention and changes

Before collection save `php artisan research:configuration > configuration.json`, the approved decisions, commit hash and exact image digest. A dirty working tree is not identified by its HEAD alone: the snapshot includes a source-file digest. Do not claim commit X deployed until the working tree is committed through the normal approved workflow and the image is built from it. Docker without Git metadata reports unknown unless its deployment supplies verified provenance; source hashes remain available.

Take read-only packages regularly with `research:export --from=<UTC ISO> --to=<UTC ISO> --output=<new directory>`. Retain the entire directory and manifest/checksums, not selected worksheets. Preserve raw research data throughout the study. Automatic deletion of Pilot raw observations is prohibited by this protocol; verify deployment retention/cleanup settings before collection. Backup cleanup is distinct from raw data retention. This documentation update does not change runtime settings or introduce deletion/pruning.

Any material configuration, code/rule, cadence or threshold change ends the current comparison segment. Save before/after snapshots and audit references, document the reason, and review a new baseline when applicable. Do not pool different protocols without explicit analysis. Do not change gap/horizon after viewing outcomes to improve headline metrics.

## Success criteria and post-pilot review

Practical acceptance requires coherent timestamps, functioning scheduler/queue, no persistent unexplained gaps, the approved 95% monitoring coverage target, complete reproducible exports, successful signal/reference workflow, manageable storage/resource use and no unacceptable service impact. The 95% target is an operational Pilot acceptance policy, NOT a universal statistical/SPC rule. It does not change coverage calculations, make missing observations healthy, or relax P subgroup eligibility. Other service-specific operational limits remain to be recorded.

After pilot, inspect histogram/distribution, outliers without automatic deletion, ACF/autocorrelation, hour-of-day and day-of-week patterns, missingness, coverage and queue delay using Minitab or another validated statistical tool. Assess normal-limit/binomial assumptions and clustering. Then decide the formal Phase I period and baseline policy. Pilot completion creates no baseline and starts no next stage automatically.
