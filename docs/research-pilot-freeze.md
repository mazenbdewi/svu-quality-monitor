# Research Pilot release freeze — research-pilot-v1

## Release identity

- Freeze verification recorded at: 2026-09-20T06:05:52+00:00 (UTC; institution timezone Asia/Damascus).
- Branch: `main`; remote: `origin` (`git@github.com:mazenbdewi/svu-quality-monitor.git`).
- Pre-commit HEAD: `55a526c36d7e4baacfef293130c4734046e30181`.
- Commit message: `Research pilot freeze after R1-R5`.
- Annotated tag: `research-pilot-v1`; annotation: `Research pilot frozen version after R1-R5`.
- Final commit identity: the commit pointed to by `research-pilot-v1^{commit}`. Obtain the exact full hash with `git rev-parse 'research-pilot-v1^{commit}'`; verify it matches this document's release with `git log -1 --format=%H -- docs/research-pilot-freeze.md` in the frozen checkout. The tag annotation and final delivery report record the literal final SHA. A commit cannot embed its own SHA in a file that contributes to that SHA; this resolvable Git reference preserves the requested single-commit freeze without a second documentation commit or dirty post-commit edit.
- Status after successful commit/tag/push verification: **FROZEN FOR PILOT**. This report is prepared in the release commit; Git refs and remote equality are the evidence of publication, not a claim that publication preceded this file.

## Final checks and repository scope

- PHP CLI: **8.4.24**; Laravel Framework: **12.62.0**.
- `php artisan test`: **452 passed, 2254 assertions, 0 failures**, 55.96 seconds.
- `./vendor/bin/pint --test`: passed.
- `git diff --check`: passed; the staged diff is checked again before commit.
- Before the release report: 53 modified tracked files and 70 new files, all belonging to the intended R1–R5 changes. Adding this report gives **124 files** in the freeze commit. No existing history is squashed.
- Reviewed tracked/untracked paths and credential-pattern matches. No actual environment file, production secret/private key/token, test database, temporary export, browser artifact or generated research package is included in the changes. Credential-like literals in tests and isolated Docker configuration are deliberate dummy fixtures, not operational credentials. Existing `.env.example` and `.env.docker.example` templates are unchanged.
- Test support scripts and `docker/compose.research-test.yml` are intentional reproducibility assets; their generated databases/packages remain outside the commit. Temporary test logs are outside the repository.
- Freeze preparation changes only this report. All pre-existing R1–R5 candidate files were checked against their initial SHA-256 content hashes. No scientific code, feature, migration name or algorithm is changed by the freeze task.

## Research versions

| Component | Frozen version / provenance |
|---|---|
| Protocol | R1–R5, with researcher-approved Pilot policies dated 2026-09-20; identified by this release tag and `research-pilot-protocol.md`. |
| Configuration snapshot format | `research-freeze-r5-v1` |
| Measurement | R1 code-defined; not independently stamped on every raw check. |
| Research eligibility | `r1-research+r4a-record-cutoff-v1` |
| Reliability / SLA | R2 measurement context / existing SLA semantics; exact algorithms identified by release code, without invented per-row version stamps. |
| Exploratory SPC | `spc-r3-v1` |
| Phase I calculation | `spc-phase1-r4a-v1` |
| Phase I methods | `individuals-moving-range-2-v1`, `pooled-binomial-proportion-v1` |
| Phase II calculation / signal rule | `phase2-r4b-v1` / `point-outside-limit-v1` |
| Exposure | `observed-evaluator-state-one-collection-interval-v1` |
| Episode grouping | `detection-gap-v1`, identified by R4B code and stored gap/context. |
| Assessment / association | `spc-assessment-r4b-v1` / `earliest-within-horizon-v1` |

The approved protocol document is authoritative for study decisions. The unchanged configuration command still includes generic pre-approval proposal text in `decisions_to_confirm` and retention commentary; archive the approved protocol alongside its runtime/version snapshot. This freeze deliberately does not change that code. Verify actual deployment settings against the approved protocol before collection.

## Frozen Pilot parameters

- Duration: **7 complete days (168 hours)**.
- Services: **4–6 authorized HTTP/API services**, representing different functions.
- Monitoring interval: **5 minutes**.
- Research sample: automatic checks only under existing eligibility; manual checks remain operational and excluded from the direct research sample.
- Planned maintenance: recorded and excluded under existing research policy.
- Storage: **UTC**; analysis: **Asia/Damascus**.
- Research Core: **I / MR / P**; Pilot P aggregation: **hourly**.
- Episode gap: **30 minutes**; incident association horizon: **60 minutes**.
- Coverage target: **95%**, an **operational Pilot acceptance policy, NOT a universal statistical/SPC rule**. No denominator, calculation, or P subgroup eligibility is changed.
- Preserve raw research data throughout the study; no automatic deletion of Pilot raw observations.
- Log major configuration changes and treat them as measurement-phase boundaries.
- Necessary manual intervention is allowed; record timestamp, reason and action and review affected segments.
- No synthetic failures against real university services; synthetic scenarios only in isolated test environments.
- Pilot completion must not automatically create or approve a Phase I Baseline.
- Purpose: measurement/data-quality validation, not final thesis inference.

## Migrations awaiting deployment

These are the four pending research migrations, in the unchanged order verified by R5 fresh MySQL 8.4 installation, legacy upgrade, rollback and reapplication:

1. `2026_09_16_000001_add_reliability_measurement_context.php`
2. `2026_09_17_000001_add_spc_research_context.php`
3. `2026_09_18_000001_create_control_chart_baselines.php`
4. `2026_09_19_000001_create_spc_phase_two_research.php`

R5's recorded operating-image ledger had the preceding 19 migrations. The fresh test applied 23; the upgrade test preserved original data in 12 tables through all three transitions. This freeze does not query or mutate the operating database. Recheck its actual ledger during authorized deployment. No migration file is renamed or edited here.

**Pilot has NOT started. Production research migrations have NOT been applied by this work.** No Docker deployment, migration command, database seed command or production backup was executed in this freeze task. The requested full test suite uses its isolated test database and its existing test fixtures.

## Remaining deployment-only steps

Follow `pilot-deployment-runbook.md` in order under separate deployment authorization:

1. Complete the service roster/permissions/settings, actual UTC dates, preliminary Phase II reference decision, custodian/archive and operating review details listed in `Research Decisions To Confirm`. Approved policy values above remain resolved.
2. Check out and verify `research-pilot-v1`, record its final commit SHA, build release app/web images from that commit and record their digests; do not substitute a previous pre-freeze dirty image.
3. Perform the runbook's image checks and inventory the server migration ledger and role customizations.
4. Create and verify the required production backup, including an isolated restore check, before any production migration.
5. Execute the controlled deployment sequence: pause workers/collection, apply authorized forward migrations, required permissions, cache clear and matching container recreation; verify health, clocks, pages and exports.
6. Archive the approved protocol, release identity, configuration snapshot and retention/backup evidence.
7. Only after explicit start approval, enable the approved services and record the actual Pilot start/end; verify initial automatic observations. Do not automatically create or approve a baseline.

No deployment, backup or Pilot activation is authorized or performed by this freeze report.

## Exact files included in the freeze commit

- `app/Console/Commands/CalculateControlCharts.php`
- `app/Console/Commands/CalculateReliabilityMetrics.php`
- `app/Console/Commands/EvaluateSpcPhaseTwo.php`
- `app/Console/Commands/ResearchConfiguration.php`
- `app/Console/Commands/ResearchExport.php`
- `app/Exports/Baselines/BaselineDataSheet.php`
- `app/Exports/Baselines/PhaseOneBaselineExport.php`
- `app/Exports/Reports/MinitabReadyExport.php`
- `app/Exports/Reports/Sheets/ControlChartsSheet.php`
- `app/Exports/Reports/Sheets/MinitabAllChartPointsSheet.php`
- `app/Exports/Reports/Sheets/MinitabBucketedChecksSheet.php`
- `app/Exports/Reports/Sheets/MinitabChartBucketsSheet.php`
- `app/Exports/Reports/Sheets/MinitabControlChartSummarySheet.php`
- `app/Exports/Reports/Sheets/MinitabRawChecksSheet.php`
- `app/Exports/Reports/Sheets/MinitabReliabilityMetricsSheet.php`
- `app/Exports/Reports/Sheets/ReliabilityMetricsSheet.php`
- `app/Exports/Reports/Sheets/SummarySheet.php`
- `app/Filament/Pages/SpcResearchMonitoring.php`
- `app/Filament/Resources/ControlChartBaselines/ControlChartBaselineResource.php`
- `app/Filament/Resources/ControlChartBaselines/Pages/ListControlChartBaselines.php`
- `app/Filament/Resources/ControlChartBaselines/Pages/ViewControlChartBaseline.php`
- `app/Filament/Resources/ControlCharts/ControlChartResource.php`
- `app/Filament/Resources/ControlCharts/RelationManagers/PointsRelationManager.php`
- `app/Filament/Resources/MonitoredServices/MonitoredServiceResource.php`
- `app/Filament/Resources/ReliabilityMetrics/ReliabilityMetricResource.php`
- `app/Filament/Widgets/ControlChartOverviewWidget.php`
- `app/Filament/Widgets/LatestReliabilityMetricsWidget.php`
- `app/Filament/Widgets/OverviewStatsWidget.php`
- `app/Filament/Widgets/ResearchFindingsWidget.php`
- `app/Jobs/CheckMonitoredServiceJob.php`
- `app/Jobs/EvaluateSpcPhaseTwo.php`
- `app/Models/Builders/ImmutableResearchBuilder.php`
- `app/Models/ControlChart.php`
- `app/Models/ControlChartBaseline.php`
- `app/Models/ControlChartBaselineSample.php`
- `app/Models/ControlChartPoint.php`
- `app/Models/MonitoredService.php`
- `app/Models/ReliabilityMetric.php`
- `app/Models/ServiceCheck.php`
- `app/Models/SpcIncidentLink.php`
- `app/Models/SpcMonitoringEvaluation.php`
- `app/Models/SpcMonitoringPoint.php`
- `app/Models/SpcResearchRun.php`
- `app/Models/SpcSignal.php`
- `app/Models/SpcSignalEpisode.php`
- `app/Monitoring/MeasurementLimits.php`
- `app/Reports/ComprehensivePdfReport.php`
- `app/Services/AdministrativeAudit.php`
- `app/Services/Baselines/BaselineConfiguration.php`
- `app/Services/Baselines/BaselinePopulation.php`
- `app/Services/Baselines/BaselineStatistics.php`
- `app/Services/Baselines/PhaseOneBaselineService.php`
- `app/Services/Baselines/ResearchSnapshotEncoding.php`
- `app/Services/ControlChartCalculator.php`
- `app/Services/ExecutiveDashboardService.php`
- `app/Services/MonitoringCoverageCalculator.php`
- `app/Services/ReliabilityMetricCalculator.php`
- `app/Services/ReliabilityTimeIntervals.php`
- `app/Services/Research/ResearchConfiguration.php`
- `app/Services/Research/ResearchPackage.php`
- `app/Services/ResearchInterpretationService.php`
- `app/Services/ServiceCheckRunner.php`
- `app/Services/Spc/PhaseTwoEvaluator.php`
- `app/Services/Spc/PhaseTwoPopulation.php`
- `app/Services/Spc/SafePhaseTwoDispatch.php`
- `app/Services/Spc/SpcResearchEvaluation.php`
- `app/Services/SpcAnalysisWindow.php`
- `app/Services/SpcResearchData.php`
- `config/monitoring.php`
- `database/migrations/2026_09_16_000001_add_reliability_measurement_context.php`
- `database/migrations/2026_09_17_000001_add_spc_research_context.php`
- `database/migrations/2026_09_18_000001_create_control_chart_baselines.php`
- `database/migrations/2026_09_19_000001_create_spc_phase_two_research.php`
- `database/seeders/DatabaseSeeder.php`
- `database/seeders/RolesAndPermissionsSeeder.php`
- `docker/compose.research-test.yml`
- `docs/audit-coverage.md`
- `docs/minitab-research-validation.md`
- `docs/monitoring-engine.md`
- `docs/pilot-deployment-runbook.md`
- `docs/reliability-methodology.md`
- `docs/research-measurement-protocol.md`
- `docs/research-pilot-freeze.md`
- `docs/research-pilot-protocol.md`
- `docs/research-readiness-r5.md`
- `docs/research-workflow.md`
- `docs/spc-phase1-baseline-methodology.md`
- `docs/spc-phase2-monitoring-methodology.md`
- `docs/spc-research-methodology.md`
- `lang/ar/administration.php`
- `lang/ar/baselines.php`
- `lang/ar/monitoring.php`
- `lang/ar/spc_phase2.php`
- `lang/en/administration.php`
- `lang/en/baselines.php`
- `lang/en/monitoring.php`
- `lang/en/spc_phase2.php`
- `resources/views/filament/baselines/review.blade.php`
- `resources/views/filament/infolists/control-chart-graph.blade.php`
- `resources/views/filament/pages/spc-research-monitoring.blade.php`
- `resources/views/reports/executive-monthly-pdf.blade.php`
- `routes/console.php`
- `tests/Feature/AdministrativeAuditCoverageTest.php`
- `tests/Feature/BackupTest.php`
- `tests/Feature/ControlChartCalculatorTest.php`
- `tests/Feature/MaintenanceWindowsTest.php`
- `tests/Feature/MonitoringCoverageTest.php`
- `tests/Feature/MonitoringEngineTest.php`
- `tests/Feature/OperationalReadinessTest.php`
- `tests/Feature/PhaseOneBaselineTest.php`
- `tests/Feature/PhaseTwoDispatchIsolationTest.php`
- `tests/Feature/PhaseTwoMonitoringTest.php`
- `tests/Feature/ReliabilityMetricCalculatorTest.php`
- `tests/Feature/ReliabilityTimeSemanticsTest.php`
- `tests/Feature/ReportsExportTest.php`
- `tests/Feature/ResearchHistoricalReportTest.php`
- `tests/Feature/ResearchInterpretationServiceTest.php`
- `tests/Feature/ResearchMeasurementProtocolTest.php`
- `tests/Feature/ResearchMigrationUpgradeTest.php`
- `tests/Feature/ResearchReadinessTest.php`
- `tests/Feature/SpcResearchEvaluationTest.php`
- `tests/Feature/SpcResearchFoundationTest.php`
- `tests/Support/ResearchMigrationScenario.php`
- `tests/Support/r5-runtime-smoke.php`
