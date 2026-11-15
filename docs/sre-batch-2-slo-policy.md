# SRE integration, Batch 2: provisional SLI/SLO calibration policy

This is a research-only, read-only policy (`sre-slo-calibration-v1`). It does
not create an approved SLO, alter the existing SLA target, or change SPC
baselines, Phase II signals, classifications, measurements, or incidents.
It adds no migration, scheduled recalibration, approval path, or dashboard.

## Population, three SLIs, and latency percentiles

The population is the existing eligible automatic HTTP/API research checks:
manual, diagnostic, synthetic-fixture, maintenance-tagged, and checks inside
recorded applicable maintenance intervals are excluded. Calibration uses
Batch 1's latest 28 **complete institution-local calendar days**, converted
to UTC `[start,end)`. The exclusive end is also the data cutoff: checks
created or modified after it are excluded. Each service is analyzed alone.

| Indicator | Numerator | Denominator | Empty denominator |
| --- | --- | --- | --- |
| Availability SLI | Executed eligible checks with `is_success = true` | All executed eligible checks | `null`, not 100% |
| Conditional successful-latency SLI | Successful eligible checks with a valid nonnegative duration `<= latency objective` | Successful eligible checks with valid nonnegative duration | `null`, not 100% |
| Combined success-and-latency SLI | Executed eligible checks that succeed with a valid nonnegative duration `<= latency objective` | All executed eligible checks | `null`, not 100% |
| Latency percentiles | R type 7 ranks of successful checks with valid nonnegative `response_time_ms` | Count of those valid successful checks | `null` percentiles |

The three ratios are percentages (`100 * numerator / denominator`). Failed
checks remain in the availability and combined denominators but neither
numerator; they are absent from the conditional successful-latency denominator.
A successful check with missing/invalid latency contributes to availability
but not the conditional denominator, combined numerator, or percentile sample.
Failed checks and invalid latencies are separately counted. Missing expected
opportunities are **not**
executed checks; they appear as coverage uncertainty, never as successes or
zero-latency observations. This is synthetic monitoring check duration, not
browser page load, TTFB, or load-balancer request latency.

For the same threshold and eligible population, combined SLI is always at
most availability SLI because every combined good event is also an
availability good event. With nonempty denominators, combined compliance can
be written as availability fraction × valid-latency fraction among successes
× conditional successful-latency fraction. Therefore a conditional P99
near 99% cannot by itself imply a 99% combined SLI.

## Deterministic calibration proposal

Batch 1's measured P90 and P99 are left unchanged. For each non-null measured
percentile `x` milliseconds, the corresponding provisional threshold is
`max(100, 100 * ceil(x / 100))` milliseconds. This is upward rounding to the
next 100 ms (exact multiples stay unchanged), with a 100 ms floor for zero.
The P90 threshold is a diagnostic fast-response guardrail. The P99 threshold
is the candidate duration objective for the **combined** SLI. A provisional
99% combined compliance percentage accompanies it. These choices are
project research-policy parameters, **not Google-mandated numbers** or
validated user-experience standards. There is no derived availability target;
that remains `null` pending business and user requirements. Existing
`warning_response_ms`/`critical_response_ms` values are not used as objectives.

The proposal is `provisional_research_only` only if the historical latency
sample has at least the existing project exploratory minimum (currently 20)
and estimated monitoring coverage is at least 95%. The 95% cutoff is an
explicit conservative research screening choice, not an ISO or Google rule.
Otherwise status is `insufficient_evidence`. Numeric rounded candidates may
still be shown for transparency, but must not be adopted or described as
validated. Warnings include insufficient observations, low coverage, no
executed checks, threshold inversion where applicable, unversioned historical
configuration/maintenance, and the need for user-experience validation.
Even a provisionally eligible candidate needs stakeholder review.

The calibration-window combined SLI is reported as an **in-sample diagnostic
only**. Since its duration objective was derived from that same window, it
is explicitly flagged `calibration_combined_sli_is_independent_evidence =
false`. It is not independent evidence of meeting 99% and cannot be used to
approve an SLO. The proposal also reports the conditional successful-latency
SLI separately. If calibration availability is below 99%, the proposed 99%
combined target is mathematically unattainable on that calibration population
at **any** duration threshold; this is flagged without increasing the
proposed latency objective or changing the proposed compliance percentage.
If in-sample combined compliance is below 99%, that is separately flagged as
an observed calibration shortfall, **not** an independent future SLO failure.
Conversely, in-sample compliance at or above 99% is not independent proof of
future success. A conditional P99 can coexist with poor availability, invalid
durations, or poor coverage, so all dimensions must be read together.

The 20-valid-observation minimum is only a preliminary data-count screen.
At exactly 20 observations, R type 7 P99 interpolates between the two largest
values and provides weak evidence about the true tail. This version does not
estimate a confidence interval or prove tail precision, even for larger
samples; every proposal carries `p99_tail_uncertainty_not_quantified`.
The 95% estimated-coverage screen reduces missing-opportunity uncertainty
but cannot repair selection bias or prove representativeness. Neither 20
observations nor 95% coverage is a mandatory Google or ISO standard.
Exploratory percentile estimates, provisional candidate objectives, and
evidence from a later independent evaluation are distinct research stages.

## Calibration/evaluation separation and traceability

A future independent evaluation must use a frozen proposal, with evaluation
`start >= calibration_cutoff`, a completed UTC half-open evaluation interval,
and its own fixed record cutoff. It must apply the same eligibility,
maintenance, failure, invalid-latency, and coverage rules and compare current
configuration fingerprint with the calibration fingerprint. Changed request
method, endpoint, expectations, timeout, cadence, timezone, or maintenance
history can invalidate comparability; do not silently join unlike periods.
No automatic recalibration is scheduled or performed on degradation. Batch 3
should define an explicit reviewed, durable proposal snapshot and independent
evaluation lifecycle before any operational SLO can be asserted.

Each transient proposal includes service ID, policy version, exact UTC
calibration start/end/cutoff, institution timezone, R type 7 method, valid
sample count, executed/success/failed/valid-latency counts, availability,
conditional successful-latency and in-sample combined SLIs, feasibility and
in-sample shortfall flags, missing/covered/expected opportunities, measured
P90/P99, rounded candidate objectives, proposed combined compliance
percentage, rounding and adequacy rules, configuration SHA-256 fingerprint,
warnings, and a SHA-256 digest of the proposal payload. The digest detects
changed payloads; it is **not** a signature or durable approval. Without a
database snapshot, later edits to checks, service configuration, maintenance,
or timezone can change a rerun. Checks inserted or edited after the fixed
cutoff are excluded rather than retrospectively reconstructed. The proposal
is repeatable only for an unchanged historical snapshot and configuration.

## Local MySQL 8.4 verification and performance boundary

Batch 1 and Batch 2 focused tests pass against an isolated local MySQL 8.4.8
test database, including independently expected R type 7 percentiles, UTC
and daylight-saving boundaries, and read-only behavior. A separate synthetic
one-service fixture contained 40,320 one-minute checks over 28 days (39,916
successful duration observations). The complete Batch 1 analysis took about
5.8 seconds on this local machine; timing is not a production-scale claim.
The aggregate and window-rank `EXPLAIN ANALYZE` steps took about 99 ms and
134 ms respectively for simplified equivalent queries. MySQL chose a table
scan because this fixture's single service occupied the entire table; the
rank calculation sorted/materialized all 39,916 valid rows. The coverage
stream reads 1,000-row chunks and hydrates eligible timestamps, contributing
materially to end-to-end latency. The existing `(monitored_service_id,
checked_at)` index is available for selective service/time ranges, but plan
choice will vary with service count and data distribution. Additional
multi-service and production-scale plan/latency checks are required before
scheduling large fleet-wide analyses. No index or schema migration is proposed
in this batch.

Final verification of the corrected three-SLI SQL also passed on isolated
MySQL 8.4.8. On a new disposable fixture with the same 40,320-check shape,
the Batch 1 latency analysis took about 3.57 seconds, the corrected SLI
aggregate about 0.15 seconds, and the full provisional proposal about 3.65
seconds. A simplified equivalent SLI aggregate `EXPLAIN ANALYZE` took about
85 ms and chose a table scan for this single-service table. These timings
show no obvious local regression from the prior 5.8-second observation, but
they do not establish production-scale performance or predict a multi-service
optimizer plan.
