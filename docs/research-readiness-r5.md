# R5 readiness audit and evidence ledger

Pilot is not started. No commit/push or production migration is authorized by this audit. This file records engineering readiness and the researcher-approved policies in `research-pilot-protocol.md`. Approval of those policies does not start Pilot or authorize deployment.

## Classification

**READY TO FREEZE FOR PILOT.** On 2026-09-20 the researcher approved seven complete days, 4–6 authorized HTTP/API services representing different functions, five-minute monitoring, automatic-only direct research samples, operational manual checks excluded from that sample, recorded/excluded planned maintenance under existing policy, UTC storage, Asia/Damascus analysis, I/MR/P with hourly P, a 30-minute episode gap, and a 60-minute association horizon. The approved 95% coverage target is an **operational Pilot acceptance policy, NOT a universal statistical/SPC rule**; no calculation or subgroup eligibility rule changes.

Raw research data must be preserved throughout the study, with no automatic deletion of Pilot observations. Major configuration changes must be logged as measurement-phase boundaries. Necessary manual operational intervention is allowed with timestamp, reason and action recorded. Synthetic failures against real university services are prohibited; synthetic scenarios belong only in isolated tests. Pilot completion must not create or approve a Phase I Baseline automatically. Pilot validates measurement/data quality, not final thesis inference.

**Remaining activation gates:** record exact service IDs/permissions/settings, actual UTC start/end, any preliminary reference decision, custody/archive/backup details and operational review procedures. An uncommitted working tree is not a release identified by HEAD alone. Record the release commit, image/configuration freeze and verified backup through a later authorized deployment. The approved research policies are resolved; Pilot remains NOT STARTED and the repository has not yet been committed or deployed as a frozen release.

**IMPORTANT BUT NON-BLOCKING:** Phase II scans monitoring/reference history on each evaluation, so profile the chosen cadence and baseline sizes. Queue delay is measured, not guaranteed. P requires complete occupied slots and freezes the first post-close result; late telemetry lowers usable coverage. R2 coverage and current service labels/configuration do not reconstruct unrecorded historical changes. Manual checks can influence operational scheduling/incidents while remaining excluded from research samples; necessary interventions are allowed, with timestamp/reason/action recorded and the affected segment reviewed. Operational executive metadata are not an immutable record of past service activation settings. Saved research runs/packages are the historical evidence.

**POST-PILOT:** distribution/ACF/seasonality analysis, formal Phase I selection, further signal rules, sensitivity analyses and effectiveness comparisons. No machine learning, SPC notifications, automatic baseline creation or Pilot transition is introduced.

## Confirmed defects addressed in R5

- Production DatabaseSeeder used UserFactory/Faker unconditionally. It now seeds permissions in all environments and creates its deterministic demo user only in local/testing, idempotently, without Factory/Faker.
- MySQL 8.4 refused R3's drop of `control_charts_unique_period` because InnoDB used it for the service foreign key. An independent `control_charts_service_reference_index` is created first.
- Reapplying R3 after its deliberately non-destructive rollback failed because the old unique index was absent. Its removal is now conditional. The FK-support index remains on rollback.
- Historical executive output used future/current check and incident state, current open incident downtime could extend to month end, and `gmdate` wrapped displayed hours after 24. Report inputs now honor the cutoff and display total elapsed hours.
- Comprehensive PDF's latest-row subquery discarded other requested daily Reliability rows. All matching stored rows are returned. Its incident state/duration summaries now use the requested cutoff rather than current closure state/full future duration; its incident cohort remains selected by start date.
- Phase II serialized work on the operational service-row lock also used by Incident processing. It now serializes on research baseline rows, retaining signal deduplication and fixed-limit calculations without taking that operational lock.

These are deployment/report/isolation fixes. R5 does not change Reliability/SLA formulas, Incident State Machine or approved baseline scientific parameters.

## Configuration, versions and reproducibility

`research:configuration` emits JSON with current safe service settings, keyed configuration fingerprints, approved-reference metadata, clocks, policies and code provenance. It never exports endpoint/header/token/password/keyword values. Code provenance includes HEAD if available, dirty status when Git metadata exist, and hashes of source/config/schema/protocol inputs. Image provenance must also be recorded at deployment; no unverifiable Git commit is invented inside images lacking `.git`.

Measurement policy and R2/SLA calculation semantics are identified by source version; raw checks and R2 rows do not independently stamp every algorithm version. Exploratory charts store `spc-r3-v1`; baseline rows store their calculation/eligibility/method and version; Phase II points/signals store `phase2-r4b-v1` and rule version; episode gap/context and run association version/horizon are retained. This distinction is explicit in the snapshot.

`research:export` creates a new directory of JSON/CSV plus manifest/checksums. It is read-only to the database, does not recalculate metrics or create runs, keeps live/retrospective files separate and retains raw eligible/excluded checks, stored overlapping metrics, exact baseline members and Phase II/history context. The package includes original incident times and all checks' fixed performance status/persistence/update timestamps, including cases with no Baseline. Do not discard these raw records before comparison. `created_at` is a row insertion timestamp, not a dedicated commit or notification-delivery timestamp.

Phase II jobs now retain enqueue time, job start and queue delay in existing evaluation context; no new table/column was needed. Joining points/evaluations gives recorded-source-to-detection timing. Missing legacy job timing remains unknown. Queue retry delays are real detection delay, not backdated evidence.

## Migration audit

| Migration | Effect and safety |
|---|---|
| 2026_09_16_000001 | Adds nullable Reliability measurement_context; allows unknown availability. Rollback drops context but intentionally leaves availability nullable. |
| 2026_09_17_000001 | Adds SPC identity/timezone/aggregation/mode/version/cutoff/context, plus point context. Nullable identities preserve legacy rows. R5 creates a separate service FK index before replacing old uniqueness, and supports reapplication after rollback. |
| 2026_09_18_000001 | Independent versioned Baselines and frozen membership with active/version uniqueness and timestamps. |
| 2026_09_19_000001 | Six independent Phase II evidence/episode/run/link tables, unique point/signal identities and run/incident/episode links. |

Order remains R2→R3→R4A→R4B. Names were **not renamed**: inspection of the current operating image/ledger showed the older 19 migrations, but that cannot prove no other persistent/shared environment used these names. Dates are ordering keys, not execution timers; there is no dependency reason to rename them. No duplicate migrations were created.

Research references intentionally avoid cascade deletion of historical evidence. Ordinary operational tables retain their existing foreign keys. R4A/R4B rollback necessarily drops research tables; R2/R3 rollback drops research context and is not lossless. Application/image rollback with forward schema retained is preferred, subject to compatibility review. Never delete research rows to recreate an obsolete unique constraint.

An isolated legacy upgrade scenario inserts sentinel users, role assignments/permissions, services, checks, incidents, Reliability/SLA and exploratory charts/points, then verifies their original columns through upgrade, rollback and reapplication. No migrate:fresh is used in that upgrade scenario. The first MySQL attempt exposed the FK index defect; a separate clean test database was used after the fix, preserving the failed attempt for inspection.

## Operational checks

Scheduling: automatic checks and scheduler heartbeat every minute; background health every five minutes; Reliability 00:10 UTC; SLA 00:20 UTC; exploratory SPC 00:25 analysis timezone; backups 02:00 and cleanup 03:00 UTC; Phase II every minute. Existing overlap guards remain; signal identities and baseline locks prevent repeated evidence. There is one `spc:evaluate` schedule.

Monitoring jobs: 30-second job timeout, service I/O at most 20 seconds, three attempts, uniqueness 120 seconds and overlap middleware. Phase II: 60-second timeout, three attempts; database queue retry_after defaults to 90 seconds. The deployed value must remain greater than relevant job timeout. Notification jobs keep their original retry/transport behavior. Operational persistence and Incident processing commit before safe Phase II dispatch; tests inject dispatch failure without rolling them back.

Docker testing uses project `svu-r5-audit`, its own internal network, database/storage volumes and loopback port. It does not load the operating `.env` or mount host application source. Synthetic test services use example.invalid and are disabled before scheduler checks. Production seeding and runtime tests use the no-dev image, without Faker.

## Final verification

- Approved-policy documentation update, 2026-09-20: only `docs/research-pilot-protocol.md`, `docs/research-readiness-r5.md` and `docs/pilot-deployment-runbook.md` changed in this follow-up. `php artisan test`: **452 passed, 2254 assertions, 0 failures** (55.91 seconds); `./vendor/bin/pint --test`: passed; `git diff --check`: passed. No scientific code or runtime configuration changed, and no deployment migration command, commit, push, deployment or Pilot start was performed. The full suite uses its isolated test database.
- SQLite targeted migration/measurement/report checks: passed during development.
- MySQL 8.4 legacy upgrade, rollback and reapply: passed on isolated `r5_upgrade_verified`, preserving 12 tables (including 33 permissions and 86 role grants).
- Full suite on 2026-09-20: **452 passed, 2254 assertions, 0 failures**, 57.50 seconds. `./vendor/bin/pint`: passed. `git diff --check`: passed.
- Fresh MySQL 8.4 database `r5_fresh`: all 23 migrations passed from an empty database inside the production-mode image. Production seed passed, user count remained zero, Faker was absent. These actions were restricted to the explicitly authorized isolated database; no operating database migration was run.
- Docker builds passed. Running app/queue/scheduler image: `sha256:eb039a99173b3f1b894581e9384cb609f009fb8154a39965d8353661727a3f67`; web: `sha256:7b76bf883c427550002b38c1ab141d916d2f43fe2c28adf2022d453014313f1a`. Inspected mounts contain named storage volumes only, no host source. These identify the tested images; later final documentation edits require a new release image/source snapshot before deployment.
- Nginx login returned HTTP 200 inside the isolated web container. Dashboard, Services, Reliability, Control Charts, Baselines, Phase II and Reports returned 200 through authenticated application-kernel requests. This is a page-render smoke, not a browser login/form workflow. Host loopback port access was unavailable with the internal test network; internal Nginx→PHP access passed.
- Live-mode synthetic workflow passed: normal observation → no signal; out-of-control → signal; later incident → 540-second positive lead; live run 1 and retrospective run 2 stayed separate. Configuration mismatch blocked evaluation; reviewed/approved version 2 resumed it; old parameters/membership/configuration stayed unchanged.
- P smoke passed: before close no point; at close p0=0 and n=12; subsequent missing subgroup produced no signal. The first harness attempt correctly failed approval because it supplied only one historical subgroup; the fixture was corrected to two complete subgroups, without changing application approval rules. These tiny references verify workflow only, not scientific baseline adequacy.
- The I/incident and P workflow scenarios deliberately use a simulated process clock. Their synthetic timestamps are not real proactive evidence. A separate database-queue test used the actual clock: source/enqueue `2026-09-20T05:48:55Z`, job start/detection `05:49:11Z`, recorded queue delay **16.28166 s**, one live signal. This includes intentionally waiting to start the worker and is not a throughput benchmark.
- At `2026-09-20T05:50:22Z`, PHP/application UTC and database UTC_TIMESTAMP/NOW agreed; institution time was `08:50:22+03:00`, analysis timezone Asia/Damascus. Scheduler heartbeat was `05:50:01Z`, queue heartbeat `05:50:12Z`; both healthy, pending/failed jobs zero. All synthetic services were disabled before scheduler startup; no real endpoint was probed. Synthetic future checks in the lab health snapshot belong only to the simulated workflow.
- `schedule:list` contained nine entries, exactly one `spc:evaluate` every minute. Exploratory 00:25 Damascus appears as 21:25 UTC; other daily schedules retain their documented UTC semantics.
- `research:configuration` and `research:export` both ran in the image. Command package `/tmp/r5-command-package` contained **43 files plus manifest**, with all 43 SHA-256 checksums independently verified. The earlier workflow package contained 34 files; counts vary with populated baselines/modes. Package tests also verify exclusions, secret omission and incident follow-up context. Minitab itself was not run.

Local execution logs: `/tmp/svu-r5-final-tests.log`, `/tmp/svu-r5-pint.log`, `/tmp/svu-r5-build-final.log`, `/tmp/svu-r5-mysql-upgrade-verified.log`. They are temporary evidence, not a permanent research archive. Preserve approved deployment artifacts separately.

After verification, the package and snapshot were copied to host `/tmp/svu-r5-verified-package` and `/tmp/svu-r5-configuration.json`. Only the `svu-r5-audit` containers were stopped; images and named test volumes were retained. Pilot was not started; no commit, push or production migration was performed.

## Exact R5 file scope

The working tree also contains earlier R1–R4B changes. A diff against HEAD therefore is not an R5-only diff. Files added or touched by R5 are:

- `app/Console/Commands/ResearchConfiguration.php`
- `app/Console/Commands/ResearchExport.php`
- `app/Services/Research/ResearchConfiguration.php`
- `app/Services/Research/ResearchPackage.php`
- `app/Jobs/EvaluateSpcPhaseTwo.php`
- `app/Services/Spc/PhaseTwoEvaluator.php`
- `app/Reports/ComprehensivePdfReport.php`
- `app/Services/ExecutiveDashboardService.php`
- `app/Filament/Pages/SpcResearchMonitoring.php` (formatting)
- `database/seeders/DatabaseSeeder.php`
- `database/migrations/2026_09_17_000001_add_spc_research_context.php` (existing pending R3 migration; no additional migration)
- `docker/compose.research-test.yml`
- `resources/views/reports/executive-monthly-pdf.blade.php`
- `docs/research-pilot-protocol.md`
- `docs/research-readiness-r5.md`
- `docs/minitab-research-validation.md`
- `docs/pilot-deployment-runbook.md`
- `docs/spc-phase2-monitoring-methodology.md`
- `tests/Feature/ResearchReadinessTest.php`
- `tests/Feature/ResearchMigrationUpgradeTest.php`
- `tests/Feature/ResearchHistoricalReportTest.php`
- `tests/Feature/PhaseTwoMonitoringTest.php`
- `tests/Support/ResearchMigrationScenario.php`
- `tests/Support/r5-runtime-smoke.php`

See `pilot-deployment-runbook.md` for verified-backup deployment and data-preserving rollback, and `minitab-research-validation.md` for independent numerical validation steps. No Minitab execution is claimed.
