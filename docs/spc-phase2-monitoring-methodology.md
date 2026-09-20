# R4B — Phase II monitoring and prospective association protocol

## Scope and evidence

Phase II compares new research-eligible automatic HTTP/API observations with explicitly approved, immutable Phase I references. It does not create incidents, send SPC notifications, change operational thresholds, or alter Reliability/SLA. Exploratory charts remain separate. An association with an incident is not proof of prediction or causality.

Implementation: `PhaseTwoEvaluator` detects and records; `SpcResearchEvaluation` subsequently links existing episodes to confirmed incidents. The detector never queries incidents. Evaluations, points, signals, research runs and links are append-only through application models/builders. Episode identity and origin timestamps are protected by the same application write boundary; the evaluator alone extends their count, last-detection and closure aggregates inside its service transaction. Privileged SQL can bypass application guards; database administration, backups and access control remain necessary.

## Baseline selection and compatibility

For live evaluation select the approved baseline for the service/chart whose approval and effective start are at or before cutoff, and whose effective end is absent or later than cutoff. No approved baseline means `no_approved_baseline`; no exploratory fallback. Monitoring begins at max(baseline end, effective start). Retirement stops future use; replacement starts a new version without rewriting old evidence. Baseline parameters and frozen membership are verified by the Phase I reproduction service before use.

Current configuration must match the approved fingerprint, including endpoint, service type, request configuration, interval, thresholds, eligibility policy and Phase I method semantics. Mismatch records `baseline_incompatible`, produces no research points/signals, and leaves operational monitoring running. The mismatch is latched for that baseline/mode: restoring old settings alone does not resume research use. Review and a newly approved version are required to resume in v1. Compatibility is observed at evaluation times, not a claim of continuous configuration knowledge between evaluations. Historical configuration before the first evaluation cannot be established solely from the current fingerprint.

## Clocks, availability and modes

All persisted research timestamps and report controls use UTC; P calendar boundaries use the baseline's frozen analysis timezone. Intervals are half-open except explicit point cutoffs and the inclusive association deadline.

* `observed_at`: check time for I/MR; subgroup start for P.
* `detected_at`: actual evaluator clock in **live**, at second precision. A delayed/catch-up job records its actual execution time, never the historic observation time. Source creation and last-update times must be no later than the cutoff. Future observations cannot contribute.
* `data_cutoff`: latest admissible information time; the live caller cannot override it.
* `retrospective`: caller-specified simulated cutoff no later than real execution, separate identities/episodes/reports. `executed_at` records the real clock in evaluation context. Replay must advance chronologically; reruns at the same cutoff are idempotent. Historically approved references that are now retired are allowed only inside their old effective interval. Backtests cannot establish that a live warning actually existed then.

Historical replay is constrained by available raw records and current configuration compatibility; it cannot reconstruct overwritten raw records or an unrecorded configuration history. Saved live evidence and saved evaluation reports remain the source of truth for previous runs.

## Calculations and boundary policies

Only I, MR and P are supported. Rule `beyond_3sigma_limit`, version `point-outside-limit-v1`, calculation `phase2-r4b-v1`: strict `value > UCL` or `value < LCL`. Equality is not a signal. No run/trend or Western Electric suite.

**I:** one eligible successful response time, baseline CL/UCL/LCL unchanged. Failures, manual checks, maintenance, synthetic/non-research checks and non-HTTP/API checks do not supply latency measurements.

**MR:** absolute difference between the current and preceding eligible Phase II response time ordered by `(checked_at, id)`. The first Phase II observation has status `awaiting_previous` and no MR value. Never borrow a Phase I point. A recorded inactive MR assessment between measurements resets the pair boundary. MR-bar and limits remain those of the baseline. Previously evaluated measurement values are read from frozen point context, even after raw edits/deletions. Late out-of-order MR observations are archived as `late_out_of_order` without a range or signal and do not rewrite the existing pair chain. Each point stores both source IDs and the prior measurement used, allowing the recorded comparison to be examined without reinterpreting historical signals.

**P:** only full closed calendar hourly/daily subgroups starting at or after monitoring start. An initial partial calendar subgroup is skipped. `n` is observed eligible automatic HTTP/API checks; `d` is the R1 problematic count (functional failure OR successful slow response). With frozen baseline `p0`, value is `d/n`, UCL is `min(1,p0+3*sqrt(p0*(1-p0)/n))`, LCL is `max(0,p0-3*sqrt(p0*(1-p0)/n))`. No Phase II re-estimation of p0. Each point stores its own n, limits, membership and coverage.

Coverage requires every expected collection slot to be occupied after removing planned maintenance exposure. Duplicate observations in one slot cannot fill a different missing slot. All observed checks still contribute to n, so duplicates can change the binomial effective sample size; independence and scheduler duplication must be assessed before interpreting nominal false-alarm rates. Empty subgroups are `missing`; partially covered subgroups are `insufficient_coverage`; neither can issue a final signal. Degenerate baseline p0=0/1 has degenerate limits and requires scientific review, not automatic smoothing.

The first post-close evaluation freezes each P subgroup, including a missing/insufficient outcome. Late arrivals do not retrospectively upgrade that live decision. They may be studied in a separately labelled replay. This conservative policy makes availability and actual detection auditable; late/missing telemetry reduces coverage rather than inventing timely evidence.

## Frequency and failure isolation

After an automatic check and Incident processing commit, safe dispatch queues the Phase II job. The `spc:evaluate` command runs every minute for services with approved baselines, also evaluating newly closed P groups when no new check triggers a job. Daily exploratory/report jobs are unchanged.

Dispatch and synchronous-driver failures are caught after operational commit; asynchronous job failure uses queue retry/failure handling. No SPC notifications are sent. Production should use the existing asynchronous queue worker; a synchronous driver runs after commit but increases request/job latency. Queue congestion increases measured detection delay and is not backdated away. Schema installation and scheduler/worker health must be verified before collecting live evidence.

R5 hardening serializes concurrent evaluators using that service’s research baseline rows. It does not hold the operational service-row lock used by Incident processing. Unique point/signal identities use baseline, chart, source, mode and rule version. Reruns never replace detection timestamps. A future algorithm with different signal semantics must introduce a new rule identity and explicit evaluation protocol, not silently overwrite v1.

## Episodes and association protocol

`SPC_SIGNAL_EPISODE_GAP_MINUTES=30` is a declared v1 design default, not an empirically optimal gap. Same service/chart/baseline/direction/rule/mode with successive **detection** times no more than the stored gap apart forms one episode. In-limit points do not by themselves end an episode; inactivity beyond gap closes it on the next evaluation. Raw signals are all retained. Episode counts in historical reports are reconstructed from raw signals available by the run cutoff, not the current aggregate count.

`SPC_INCIDENT_ASSOCIATION_HORIZON_MINUTES=60` is the prespecified v1 association window, also a design decision. Register both parameters before a pilot; do not tune them on outcomes. Any later sensitivity analysis must be labelled separately. Changing gap does not regroup old episodes: a report includes episodes carrying its protocol gap. Changing horizon creates a new immutable report, preserving the old one.

Only independently confirmed incidents (`confirmed_at <= report cutoff` and incident record available by cutoff) from the same service may link. Start time is the first failure in the confirmed chain, **not** confirmation time. Candidate/unconfirmed incidents are absent from the confirmed-outcome denominator until confirmation is available. Planned-maintenance starts are excluded. Eligible association requires `0 < started_at - first_detected_at <= horizon`; same-time or post-incident signals are not proactive.

The **earliest eligible episode within the horizon** is primary for lead time; the nearest is stored as a secondary comparator. All eligible pairs are retained in a run-specific link table. Each incident is counted once per service/chart/baseline breakdown regardless of the number of linked episodes. Each episode is counted once in precision even if it links to multiple incidents.

## Actual monitored exposure and censoring

Approval alone does not establish that Phase II ran. Each compatible evaluation with recent eligible check evidence stores an active exposure interval of at most one configured collection interval. The next evaluation (including incompatible/no-data status), retirement, report cutoff and maintenance trim it. P additionally requires the latest finalized subgroup to be eligible. This is a conservative declared observation-state policy, not an assertion of continuous service health.

An incident must start inside observed Phase II exposure with continuous prior exposure from max(first observed Phase II time, start minus horizon) to its start. Outside/incompatible/missing-observation periods are excluded with reasons. Starting a baseline creates left truncation: the initial incident look-back may be shorter than horizon and is visible in exposure timestamps. Compare such cohorts cautiously.

An episode enters the precision denominator only when its entire horizon has elapsed by the cutoff AND observed exposure covers that follow-up continuously. Otherwise it is `censored_incomplete_followup`, even if a link is already known. Missing telemetry cannot be interpreted as healthy or as proof that no outage occurred. A maintenance gap censors follow-up. Eligible unmatched episodes are `unmatched_signal`, not intrinsically false alarms; they may be performance degradation without outage.

A report's recall cohort is incident starts in `[period_start, period_end)`. Its precision cohort is episode first detections in that interval. Pre-start episodes up to one horizon earlier may explain recall; incidents up to one horizon after report end may establish follow-up for precision. The cutoff bounds all knowledge. These differing cohort boundaries are intentional.

## Metrics and historical reports

* Incident recall = distinct eligible incidents with prior eligible episode / eligible confirmed incidents.
* Episode precision = eligible mature episodes with an eligible incident / eligible mature episodes.
* Lead seconds = incident start minus primary episode first detection, without early minute rounding; positive only. Reports give mean/median/min/max.
* Empty denominators produce null, not zero or 100%.
* I/MR/P remain separate. Optional Any-SPC recall unions incident IDs across eligible chart/baseline groups; it does not sum counts or claim cross-chart episode precision.

Each immutable run stores period, cutoff, mode, evaluation version, association policy, horizon, gap, baseline versions, metrics, incident/episode inclusion and exclusion datasets, observed exposure and timeline snapshots. Rerunning creates another run. Old runs are not changed when an incident is later confirmed/recovered, the horizon changes, or more observations arrive. Application-privileged deletion/edit of incident or maintenance records can affect a *new* analysis; old reports preserve what was used.

## UI and fixed-threshold readiness

The separate Phase II research page uses existing control-chart view permission. Authorized baseline managers can generate reports; viewers can read evidence. It shows approved/retired versions, compatibility and last evaluation, live versus retrospective mode, recent points/signals/episodes, per-point P limits, report metrics, links and an incident timeline. It is not a redesign of the operational dashboard. JSON export carries the frozen run and links; R4A Minitab baseline export and R3 exploratory exports remain unchanged.

I/MR point context records existing R1 fixed performance status and source availability time. Timelines show baseline activation, SPC detection, first available warning/critical measurement within horizon, incident start, confirmation and recovery known by cutoff. This is readiness data for a later comparison, **not** a completed comparative effectiveness study. A threshold's source-availability time is not proof a notification was delivered. P-only runs do not currently reconstruct the fixed-threshold comparator from every subgroup member; comparison may use the I/MR records or R1 raw history separately.

## Limits and pre-pilot work

No pilot is started automatically. Before prospective collection: review and approve scientifically stable service-specific baselines; prespecify gap/horizon and subgroup interval; authorize/apply schema in deployment; verify asynchronous workers, scheduler, queue delays, clocks and retention; define treatment of configuration changes and late arrivals; test expected load and cadence; assess serial correlation, seasonality, non-normal latency and P independence/overdispersion. I/MR sigma limits on autocorrelated/skewed series and P normal limits on small n may not have textbook false-alarm properties. Sufficiency cannot be established from passing software tests.

The initial evaluator scans reference-period monitoring history and validates frozen baseline membership each time. This prioritizes auditability for R4B, but long high-frequency streams require measured capacity planning and a separately reviewed incremental implementation before a large pilot. Near-real-time detection depends on operational queue/scheduler availability; the software does not promise a fixed notification SLA. Association metrics test whether recorded warnings preceded independently confirmed outages under a prespecified observable protocol, not whether warnings caused prevention.

Migration `2026_09_19_000001_create_spc_phase_two_research.php` adds six independent research tables. It does not rewrite legacy checks, incidents, Reliability, SLA or approved baseline rows. Reference IDs intentionally avoid cascade deletion of historical research evidence. No production migration or Pilot is performed by this development task.
