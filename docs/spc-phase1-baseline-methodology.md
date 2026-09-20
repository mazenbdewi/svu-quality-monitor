# Phase I Baseline methodology — R4A

R4A implements explicit, versioned Phase I research references for I, MR and P. It does not implement Phase II monitoring, notifications, incident linkage, lead time, precision or recall. Existing R3 exploratory charts remain independent and are not promoted or recalculated automatically. Reliability, SLA, the incident state machine and R1 coverage service are unchanged.

## Selecting a Phase I period

A researcher selects one service, chart, historical interval, timezone and P aggregation (hourly or daily). Select a period for substantive reasons: comparable endpoint, measurement protocol and operating conditions, representative time coverage, and a reviewable distribution and signal history. A 30-day window does not become a baseline merely because it exists or has elapsed. No scheduler or command approves baselines.

The UI's Create Phase I Baseline action is separate from exploratory calculation. Calendar input is interpreted in the selected timezone, defaulting to the R3 analysis timezone; explicit-offset service inputs preserve their instants. UTC is retained in storage and exports. Boundaries have one-second resolution and are half-open `[start,end)`. Date-only end is exclusive: an entire September ends October 1 at local midnight. Require `start < data_cutoff <= baseline_end <= creation_time`. The UI uses end as cutoff; the service also accepts an earlier explicit cutoff, recorded as partial. Future periods are rejected instead of silently called completed.

## Schema and version identity

`control_chart_baselines` stores service/chart/metric, version, status, timezone, aggregation, requested start/end, data cutoff, calculation/method/eligibility versions, sample counts, frozen coverage, numerical parameters, safe configuration and compatibility fingerprint, membership digest, review context and lifecycle actors/timestamps. It also stores the effective validity interval and review/decision notes.

`control_chart_baseline_samples` stores one row per candidate check: original check ID, deterministic sequence, inclusion flag, exclusion reasons and a minimal measurement snapshot. The snapshot retains the time/provenance/outcome/latency fields used to select and calculate the sample, not a full ServiceCheck (no request headers, response body, URL, error text or arbitrary metadata).

Versions increase per service and chart type; the metric is fixed by that chart. Version allocation locks the service row and has a database unique constraint. P hourly/daily and timezone changes produce different versions within the same P family, not different simultaneously active families. A nullable unique active key enforces at most one approved version per service/chart. Replacing a reference requires explicit retirement of the active version followed by explicit approval of the reviewed replacement. Old versions and samples remain present.

A baseline's service foreign key restricts deleting the referenced service, preserving reference identity. Raw check IDs deliberately have no cascading foreign key: deleting operational check history cannot remove a baseline's frozen measurements. Historical actor IDs remain stored even if their user accounts are later removed.

## Frozen population and exclusion traceability

Selection reuses R1 eligibility through the R3 shared research-data service:

- I/MR: automatic HTTP/API, outside persisted/applicable planned maintenance, not synthetic/diagnostic, functionally successful, and non-null nonnegative response time.
- P: the corresponding binary research population, including functional failures and warning/critical successful responses; `problematic = !is_success || is_slow`.

There is an additional **baseline-only availability-at-cutoff policy**: a candidate must have a known creation timestamp no later than cutoff and must not have been updated after cutoff. A backdated check inserted later, or an earlier check edited later, is excluded because its as-of-cutoff state is not reconstructible from the live row. This intentionally can make the Phase I population smaller than a retrospective R3 exploratory sample. It does not change R1/R3 queries.

Candidates have `checked_at >= start` and `checked_at < cutoff` (and therefore `< end`). Checks exactly at either exclusive end are not included. Candidate rows retain relevant values even when excluded, plus reasons: manual/unknown source, maintenance, synthetic/diagnostic, type, functional failure, invalid latency, recorded after cutoff/unknown timestamp, modified after cutoff or a residual policy exclusion. Reasons can overlap, so their counts need not sum to the distinct excluded count. Checks outside the time window are not candidates and do not inflate exclusion counts.

Membership uses `checked_at`, then ID. MR pairs only adjacent included values inside the baseline; it never borrows a pre-start observation. As in R3, adjacency can span an excluded observation or missing time; this is visible in source IDs and time coverage. There is no discretionary outlier exclusion in v1. Signals remain in the estimation sample. A different inclusion or cleaning policy would require a separately documented policy and a new version, not editing an approved sample to narrow its limits.

Both identifiers and minimal numerical values are retained because identifiers alone would not preserve reproducibility if raw checks were corrected or deleted. A canonical JSON membership digest preserves list order but normalizes object key order across JSON storage engines. It detects accidental membership changes; it is not a cryptographic defense against a database administrator who can also rewrite digests.

## Parameters and numerical methods

The archived method identifiers are `individuals-moving-range-2-v1` and `pooled-binomial-proportion-v1`, with calculation version `spc-phase1-r4a-v1`. Eligibility is `r1-research+r4a-record-cutoff-v1`.

For included latency values x1...xn, MR_i = abs(x_i - x_(i-1)) and MR-bar is the mean of n-1 ranges.

- I stores n, mean, MR-bar, sigma = MR-bar/1.128, CL = mean, UCL = mean + 3 sigma, LCL = max(0, mean - 3 sigma).
- MR stores pair count, MR-bar, CL = MR-bar, UCL = 3.267 MR-bar, LCL = 0.
- P stores total observed n, total problematic d, p0 = d/n and the number of nonempty subgroups. Frozen bucket context preserves each subgroup's actual n_i, d_i and coverage. It does **not** store one universal P UCL/LCL as a monitoring parameter.

The I/MR formulas are the existing R3 formulas. Insufficient quantities remain null. Numerical parameters retain calculation precision; reproduction compares within a relative tolerance of 1e-10 (with absolute floor 1e-10). Exploratory Phase I P points can be displayed with their own n_i-based limits; these are review graphics only. A future Phase II P engine would derive limits from fixed p0 and the new subgroup's n_i. That engine is not implemented here.

The calculation class reads only frozen memberships and bucket boundaries when reproducing parameters and points. No live check values or live thresholds are consulted. The reproduction entry point rejects unsupported archived method/version identifiers; future methods must retain a compatible implementation for old versions instead of silently interpreting them with a different algorithm.

## Coverage, sufficiency and stability review

Frozen coverage uses R3's research HTTP/API occupied-slot policy and calendar buckets; expected opportunities respect service creation, configured interval and merged maintenance. Observed slots are recomputed from baseline-cutoff-eligible binary samples, so a late recorded/modified check cannot make missing coverage look complete. Failed functional checks are observations for coverage, not healthy outcomes. Repeated records in a slot cannot fill another missing slot. I/MR coverage uses hourly diagnostic buckets; the plotted observations remain raw. Full maintenance has no expected opportunities, and missing proportions are null, not zero.

This is research population coverage, not a modification of R1's broader operational MonitoringCoverageCalculator. The configuration used to calculate expected opportunities is snapshotted at generation; the application does not reconstruct an exact historical schedule from incomplete audit history.

Sufficiency follows R3: no included data => no_data; fewer than two computable chart points => insufficient; otherwise fewer than the configured exploratory minimum or missing slots => preliminary; otherwise analyzable. The current project minimum (default 20) is frozen with the result. It is a project reporting policy, not a universal scientific baseline-size rule. Analyzable is not automatic stability approval.

Approval blocks no_data, insufficient or absent coverage context. Preliminary is allowed **only** after an explicit recorded review, explicit acknowledgement of limitations and a separate approval rationale. The same explicit steps apply to analyzable. No new arbitrary coverage percentage threshold is introduced.

The review page shows candidate/included/excluded counts, time extent, cutoff, coverage and missing buckets, descriptive distribution (min, max, mean, median, sample SD), point count, exploratory limit exceedances, configuration warnings and an exploratory plot. Values and limits are plotted without connecting missing periods. There is no automatic outlier deletion, automatic stability certification, distributional-test engine or causal claim about exceedances.

## Configuration snapshot and compatibility

The snapshot contains service type, request method, effective timeout, check interval, warning/critical thresholds, expected status, timezone, aggregation, eligibility version and calculation method/version. Endpoint, expected keyword and measurement configuration are represented only by keyed HMAC fingerprints. Passwords, tokens, authorization headers, request bodies and raw URLs are not copied. A canonical encoding avoids fingerprints changing merely because JSON object keys were reordered.

Within-period administrative audit entries are reduced to timestamps, audit IDs and names/markers of changed measurement fields; raw secret values are not copied. The page separately compares the current service fingerprint to the frozen one and displays a warning when they differ. Audit history may miss direct database edits or pre-audit changes, and the snapshot represents creation-time configuration, not guaranteed configuration at every historical observation. The reviewer must acknowledge that limitation. Key rotation can change HMAC fingerprints without a substantive endpoint change; investigate the warning rather than treating it as an operational failure.

Compatibility is exposed as a read-only comparison for future R4B use. It never stops checks, changes incidents, retires a reference or selects a new reference automatically.

## Lifecycle, authorization and history

The lifecycle is `draft -> reviewed -> approved -> retired`, with `draft/reviewed -> rejected`. Neither a rejected nor retired version is reactivated or erased. No-data/insufficient versions can be reviewed and rejected but cannot be approved. Review records actor/time/notes and explicit acknowledgement; approval records actor/time/rationale. `effective_from` is the actual approval instant; `effective_to` is the retirement instant, with future interpretation as a half-open validity interval. This does not run Phase II selection.

The permission `control_charts.baselines.manage` is added to the existing RBAC seed definition for administrator and super_admin. Operator and viewer do not receive it. View/export access requires `control_charts.view`. Every lifecycle/generation service operation rechecks a fresh, active actor and permission, independent of action visibility. The page disables ordinary create/edit/delete routes and offers only the dedicated actions. Export rechecks its read permission.

The creator and reviewer/approver may be the same authorized researcher; R4A requires separate explicit actions, not two different people. Institutions needing a two-person approval rule can add that policy later.

Scientific fields and membership are frozen from the end of generation, including draft versions. Even draft rebuilds use a new version: this is deliberately simpler than mutable drafts. Ordinary model saves, deletes and common bulk update/upsert operations are blocked; membership cannot be extended once sealed. The lifecycle service has a narrow write path for lifecycle columns only. This is application-level immutability, not a database WORM system: privileged raw SQL, schema rollback and restores remain administrative trust boundaries.

Creation (entity, measurements, parameters, seal and audit) is atomic. Lifecycle changes and their audit event are also transactional. A failed membership insert or failed approval audit rolls back all associated writes. Events are `baseline.created`, `.reviewed`, `.approved`, `.retired` and `.rejected`, with baseline/version/service/chart/period/actor and decision context. The row's decision_reason is the latest decision; each previous review/approval/retirement rationale remains in its audit event.

## Research export and Minitab reproduction

Export is a dedicated workbook from the Baseline review page, not a live R3 date-filter query. It contains:

1. Baseline summary: identity/version/status, period/cutoff/timezone, policy and calculation versions, parameters, counts, safe configuration/fingerprints, coverage summary and lifecycle context.
2. Membership: exact candidate IDs/order, inclusion flag, exclusion reasons and frozen measurement/provenance values. Filter `included = 1` for the estimation sample.
3. Phase I Buckets: the frozen expected/observed/missing/coverage and d_i/n_i context, including missing groups.
4. Phase I Points: all reproduced exploratory values/limits/signals and source IDs/pair IDs where applicable.
5. Configuration Warnings: one row per preserved audit marker, avoiding an oversized spreadsheet JSON cell.

For I/MR validation in Minitab, filter included rows and preserve sequence order, using response_time_ms as individual observations and a moving range of two. For P, use observed nonempty subgroups with problematic_count as d_i and observed_count as n_i; never fill missing groups with p=0. Align constants, three-sigma rules, limit truncation and rounding when comparing results. No actual Minitab application execution is claimed by the automated tests; they prove exported membership, parameters and per-point values match the application fixture. Spreadsheet strings are bound as literal text, so reviewer notes cannot become formulas.

Export first verifies membership integrity and reproduces stored parameters. Changes/deletion of live service checks do not change this workbook's Phase I measurements. Human labels on a live service may change; the original service name is retained in review context.

## Deployment and validation scope

The additive migration creates the two baseline tables; it does not convert historical R3 charts or backfill a baseline. Existing deployments need the new schema and the RBAC permission assignment before use. No production migration or permission seeding is performed by this implementation task; tests use the isolated test database. A schema rollback drops research history, so it is not a retirement mechanism.

The PhaseOneBaseline tests cover known I/MR/P fixtures, deterministic MR boundaries, variable P subgroup sizes, draft/review/approval rules, permissions and revoked roles, immutability, version coexistence/retirement, audit events, secret-free snapshots/exports, future/end/cutoff/late-record rules, Damascus and month boundaries, reproduction after raw edits/deletion, transaction failure, literal spreadsheet export and the actual review UI. Full-suite verification also retains R1, R2, R3, SLA and incident regressions.

Generation/export currently process a selected baseline synchronously in memory; very large study populations need capacity testing and may require batching in a later implementation.

Remaining limitations are explicit: no complete historical configuration reconstruction, no discretionary cleaning workflow, no empirical proof of stable/independent observations merely from approval, no archival of old software binaries and no defense against privileged database tampering. Retain the method implementation, exports and database backups with the study record.

R4B remains unimplemented: Phase II engine and reference selection, fixed monitoring parameters, variable-n P monitoring limits, signal event records, lead-time/precision/recall evaluation, incident linkage and SPC notifications.
