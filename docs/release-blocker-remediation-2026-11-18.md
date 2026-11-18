**Release blocker remediation — SVU Quality Monitor, 18 November 2026**

Base: `58a097fef92b42080b0809becd33279be88cfa62`. The remediation batch is approved for a new local commit; this report records pre-commit acceptance. All Docker actions in this review target disposable `svu-release-remediation*` projects and synthetic schemas. No production deployment, push, commit rewrite, dependency upgrade, or permission reset was performed.

**1. Root causes**

The unchanged base commit was reproduced against an isolated MySQL **8.4.8** server with `STRICT_TRANS_TABLES`, `ONLY_FULL_GROUP_BY`, and the other default strict modes enabled. Exactly **248 errors** and one risky-test diagnostic were reproduced:

| Cause | Errors | Correction |
| --- | ---: | --- |
| Nullable SLA target cast to `''` and written into DECIMAL | 1 | Preserve null and `not_configured`; no invented target |
| Query Builder `whereKey()` resolves to nonexistent `key` column | 1 | Query `id`; assert nonempty ciphertext, cryptographic decryption, and application decryption |
| Rollback drops the only FK-supporting service-check index | 2 | Restore a single-column index before dropping the composite index |
| Missing permissions table after interrupted schema rollback | 147 | Isolate schema lifecycle and committed fixtures |
| Missing `check_type` column after partial rollback | 91 | Same isolation correction |
| Missing institution settings table after partial rollback | 4 | Same isolation correction |
| Legacy service enum unavailable after partial rollback | 1 | Same isolation correction |
| Upgrade scenario starts on a populated shared schema | 1 | Explicit empty-schema setup and full-schema restoration |

Every original failing test is listed with its passing final MySQL result in [the 248-row inventory](acceptance/remediation-mysql-failure-inventory.csv). These were not 248 independent application defects. SQLite previously accepted the bad encrypted-configuration test because its missing raw value became an empty string; the new assertions close that false-positive path.

Ascending-ID scheduling repeatedly selected the same incomplete services. Temporary uniqueness locks did not survive a long worker outage or a later daily cutoff. Dispatch counters counted attempts rather than guaranteed durable enqueues. The worker was absent from deployment/rollback tooling and dedicated health reporting. Old serialized jobs also lacked the new promoted readonly ledger-ID property. The handler and failure callback now safely treat an absent ID as a legacy job; an actual old-image payload and a serialization regression test verify compatibility. A sample append guard also hydrated an entire archived JSON snapshot for every individual sample; it now selects only the ID and seal while retaining the same immutable-membership check.

**2. Implemented corrections**

The scheduler stores its round-robin cursor and run identities in SQL. A locked scheduler row serializes dispatch and backlog decisions across processes. Queue insertion, the unique service/policy/cutoff run record, and cursor advancement commit together on the application database connection. The hard ceiling is five successful unique enqueues per invocation, even if configuration requests more. A backlog limit below five is honored. Failed or rejected enqueues roll back their records and cursor movement.

Pending and reserved run identities block later-day duplicates. Physical queue payloads are also inspected safely as JSON/scalar serialized fields, including older jobs without ledger IDs and explicitly retried failed jobs. Payloads are never unserialized by the scheduler. Both UI and scheduled dispatch use the same durable controls. Completed replay is a no-op; disabled/deleted services do not create new drafts. Partial I/MR/P recovery and all existing scientific decisions remain with the existing engine.

Dispatch reports `eligible`, `dispatched`, `duplicate`, `skipped`, `deferred`, and `failed`, with explicit backlog/per-run delay warnings and failure exit status for enqueue errors. Work remains retained while capacity is unavailable.

System Operations, diagnostics, and the existing top-bar health indicator now expose the baseline worker independently of normal monitoring. Idle loop heartbeats, timestamp, pending count, reserved count, retained failures, oldest pending age, actual successful draft preparation, warning/healthy/unavailable states, startup grace, and recovered alert cycles use the existing background-health and notification architecture. A reservation may belong to an interrupted process, so both languages label it as reserved work that may be processing.

`safe-update.py` builds and pins the baseline worker and active optional workers, permits a first upgrade with no existing baseline container, verifies the pre-update backup, drains workers, applies migrations, recreates services from pinned new images, and checks real worker heartbeats. Inactive profiles stay inactive. Discovery reads all profile definitions, so workers started previously with a profile are still found even when that profile is absent from the current environment. No seeder runs. HTTP readiness has a bounded grace after nginx starts, and failure events include a safe stage and exception class. Rollback keeps maintenance enabled and all workers stopped, retains pending/failed work, and restores addressable prior images.

**3. Modified and new files**

The complete manifest is [remediation-files.txt](acceptance/remediation-files.txt). The functional groups are SLA normalization/encryption verification; the scheduler/run model and additive migration; job lifecycle and queue observability; Arabic/English views, labels, and alerts; deployment tooling and runbooks; schema lifecycle regression tests; and guarded Docker/browser measurement fixtures. Package manifests and lockfiles are unchanged.

**4. Before and after tests**

| Gate | Base commit | Corrected batch |
| --- | --- | --- |
| Complete SQLite suite | 625 tests, 3,817 assertions, pass | **642 tests, 3,951 assertions, pass** |
| Complete MySQL 8.4 suite | 625 tests, 248 errors, one risky diagnostic | **642 tests, 3,951 assertions, pass** |
| Fairness/outage/lifecycle regression class | Absent | 11 tests in both complete suites |
| Scientific regression coverage | Included | 301 test cases in each complete suite |
| Authorization/security regression coverage | Included | 133 test cases in each complete suite |
| Deployment unit/recovery tests | Incomplete worker support | 14 tests, pass |
| Real English/Arabic Chrome checks | Earlier screenshots only | 8 authenticated pages, no JS exceptions, correct LTR/RTL, no horizontal overflow |
| Laravel Pint | — | Complete `pint --test`, pass |
| PHP syntax | — | 367 PHP files, zero failures |
| Frontend production build | — | Docker Node 22/Vite production build, pass |
| Compose validation | — | Default, `slo-preparation`, and all-profile discovery, pass |
| Docker outage/restart/concurrency | Unbounded duplicate risk | Real workloads and eight concurrent schedulers, pass |
| Deployment and rollback | Baseline worker absent from tooling | Old-payload upgrade, maintenance exit, and pending-work rollback preservation, pass |
| Dependency mutation check | — | npm/Composer manifests and lockfiles unchanged |
| Git diff validation | — | `git diff --check`, pass |

The complete suite is executable with `php vendor/bin/phpunit` for SQLite and, against an **empty dedicated test schema**, with:

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33079 \
DB_DATABASE=svu_remediation_test DB_USERNAME=svu_test \
DB_PASSWORD="$MYSQL_TEST_PASSWORD" php vendor/bin/phpunit
```

Schema-isolation tests intentionally drop/rebuild their dedicated test schema. The MySQL test user requires schema migration privileges on that schema only.

Counts for scientific/security groups are descriptive selections from the complete JUnit inventory, and may overlap. No tests were skipped or assertions weakened. The complete runs report zero skips, failures, and errors. Their local wall times were approximately 140 seconds for SQLite and 335 seconds for MySQL; these include shared-host contention and are not throughput comparisons. [Machine-readable summary](acceptance/remediation-test-summary.json), [browser results](acceptance/remediation-browser-results.json), [Arabic screenshot](screenshots/remediation-operations-ar.png), [English screenshot](screenshots/remediation-operations-en.png).

**5. Performance and concurrent workload verification**

| Scenario | Normal dispatch | Normal job mean / p95 / max | Baseline attempt mean / max | Result |
| --- | ---: | --- | --- | --- |
| Normal only, 20 checks | 974 ms | 351 / 431 / 3,306 ms | — | 20/20 checks completed |
| Concurrent, 20 checks + 5 preparations | 982 ms | 171 / 207 / 769 ms | 38.93 / 44.40 seconds | 20/20 checks; 15 drafts |
| Worker outage, 15 simulated daily schedules | See raw rounds | Monitoring continues | Worker stopped | Exactly 10 unique pending jobs, bounded at the configured limit |
| Restart and backlog recovery | Six minute-level monitoring polls also recorded | 661 / 233 / 21,323 ms over 41 checks | 33.60 / 44.09 seconds | 20 completed preparations; 60 drafts; zero approvals/activations |
| Eight concurrent scheduler processes | — | — | — | Exactly 10 unique queue rows / ledger rows; no cap race |

All final workload scenarios recorded **zero failed jobs** and zero missing service results among their dispatched monitoring rounds. The recovery maximum of **21.3 seconds for one normal job is material**: baseline creation holds the same service row while archiving its samples, so a normal persistence operation can wait. The recovery p95 remained 233 ms, but operators must budget for this observed contention; no production latency guarantee is inferred. The first normal-only sample includes a roughly three-second local HTTP outlier, so the independent runs do not establish that concurrency improves latency.

The interrupted run's persisted start-to-finish span reached **139 seconds**, including the reservation timeout and restart; successful processing attempts stayed below the 55-second job timeout. Do not confuse that recovery span with CPU execution duration. Memory-threshold worker recycling also occurred and resumed work without queue duplication.

Sampled peaks during recovery were **54.06% CPU / 138.3 MiB** for the baseline container, **8.2% / 65.13 MiB** for the normal worker, and **72.76% / 631.5 MiB** for the disposable MySQL server. CPU sampling can briefly exceed a 0.5-CPU quota over a short accounting interval. During the initial concurrent round, the corresponding baseline peak was 52.46% / 138.1 MiB. These are sampled maxima on a busy shared host.

Detailed events, dispatch output, queue depths, and resource samples are in [workload results](acceptance/remediation-workload-results.json) and [minute monitoring rounds](acceptance/remediation-monitoring-rounds.json). The eight-scheduler fixture deliberately left ten pending synthetic jobs for separate recovery/cleanup verification. Cleanup correctly refused with a nonzero exit while they were pending. A subsequent real worker drain completed all ten without purging work, and the existing baseline alert changed from unavailable/down to healthy after the backlog cleared. [Outage alert](acceptance/remediation-real-outage-alert.json), [recovery alert](acceptance/remediation-real-recovery-alert.json), [cleanup refusal](acceptance/remediation-cleanup-guard.json).

The isolated fixture contains **20 services and 161,280 historical measurements**, representing 28 days at five-minute intervals. The dedicated baseline worker uses the existing 0.5 CPU / 256 MiB container limits and 192 MiB PHP limit. Instrumentation records real job-processing events separately from research data. Docker CPU/memory are sampled observations, not lifetime maxima. Laravel memory recycling uses the actual production restart policy.

Required legacy scheduling remains enabled through the disabled fixed-cadence flag. Therefore exact opportunity-ledger accounting is intentionally unavailable; completion of commanded checks and historical coverage are reported separately. An empty exact-opportunity table is never presented as proof of zero missed exact opportunities.

**6. Database migration requirements**

Run `php artisan migrate --force` through the deployment procedure. One additive migration creates `baseline_preparation_runs` and a one-row `baseline_preparation_scheduler` table. They provide durable queue identities, fair progress, last-success evidence, and a transaction-scoped dispatch mutex. Cache locks and monitoring-opportunity research tables cannot supply those guarantees safely. No existing research table or approved decision is rewritten. The older index migration has a corrected `down()` implementation; its already-applied `up()` is not rerun.

Baseline dispatch currently requires the **application database queue on the same connection, with `after_commit=false`**. Other brokers are rejected explicitly because their enqueue cannot be atomic with the SQL ledger. This is the supported production Compose configuration on both SQLite and MySQL.

**7. Scientific integrity**

I/MR/P statistics, Type-7 percentiles, SLI/SLO definitions, maintenance exclusions, historic versus exact coverage, and the 14/21/28-day quantity-only selection remain unchanged. The 301 scientific cases include reproduction, immutable sealed membership, approval/rejection lifecycle, approved objectives, historical snapshots, exclusions, partial-group recovery, and researcher authorization. Automatic real-worker fixture output consists only of drafts, with no approvals or activation keys. The sample-write optimization retains the existing creating event and seal check; it changes only selected SQL columns.

Both feature flags remain false in example deployment configuration and were verified false in the running isolated stacks. Automatic Phase I preparation operated independently. Sensitive monitoring configuration was verified as nonempty encrypted ciphertext at rest, decryptable cryptographically and through the model.

**8. Deployment and rollback commands**

The production commands and compatibility restrictions are in [safe-update.md](safe-update.md) and operational diagnosis is in [operation.md](operation.md). After installing the reviewed release in the existing production directory:

```bash
cd /var/www/svu-quality-monitor
python3 scripts/safe-update.py --compose /var/www/svu-quality-monitor/compose.yaml \
  --state /var/www/svu-quality-monitor/update-state \
  --url http://127.0.0.1:8080 --dry-run
python3 scripts/safe-update.py --compose /var/www/svu-quality-monitor/compose.yaml \
  --state /var/www/svu-quality-monitor/update-state \
  --url http://127.0.0.1:8080
```

The final real upgrade began from the unchanged base image with **no baseline worker container** and one genuine old-format queued job. A verified pre-update backup, image build/preservation/pinning, graceful shutdown, additive migration, recreation, real worker checks, HTTP readiness, and eight authenticated browser page checks passed. The legacy job completed successfully. Maintenance was **false**, all worker checks were healthy, both feature flags were false, failed jobs were zero, and the historical-measurement and customized RBAC hashes matched their pre-upgrade values.

A separate deliberate rollback then retained **one new-format pending baseline job and its pending ledger record**. The prior application's job-source hash matched the base commit. Maintenance was **true**, the normal worker and scheduler were stopped, and the baseline worker container was removed. Pending payload, research-measurement, and RBAC hashes matched byte-for-byte before/after rollback. No database restore, migration rollback, queue purge, or permission seeder ran. The earlier incompatible-payload failure remains retained in the superseded isolated rehearsal schema.

Evidence: [upgrade events](acceptance/remediation-deploy-upgrade.jsonl), [successful upgrade checks](acceptance/remediation-deploy-success-check.json), [rollback events](acceptance/remediation-deploy-rollback.jsonl), [pending state before](acceptance/remediation-rollback-before.json), [state after](acceptance/remediation-rollback-after.json).

Rollback never restores the database automatically. Keep both new tables and all pending/failed queue rows. Older code may not understand new baseline payload fields; keep its baseline worker and scheduler stopped pending a reviewed compatibility recovery. Prefer forward repair to drain retained work. Do not run a full database seeder, queue purge, migration rollback, or destructive restore as an automatic recovery shortcut.

**9. Remaining limitations**

These are local isolated acceptance measurements on a shared development host, with local HTTP targets and synthetic history. They are not production performance guarantees, Internet latency measurements, high-availability proof, or a capacity certificate for larger histories. Larger one-minute datasets, slower disks, different service types, external notification transports, and production TLS/network paths require environment-specific validation. Reserved jobs cannot prove process liveness; interruption recovery respects database queue reservation timeouts. Deliberate failure injection and worker recycling are documented separately from failed jobs.

Initial deployment rehearsals exposed ambiguous Docker `app` aliases in the test network and a real legacy-payload compatibility bug: older serialized jobs lack the new promoted readonly property. Both were corrected, and the job has a serialization regression test. The earlier failed job remains in its isolated schema; it was not deleted to obtain a green result. Final acceptance uses a fresh isolated old-version installation with a genuine legacy pending job. The host has Node 20.18.1, below Vite's supported range; production compilation uses the Docker Node 22 stage.

npm remediation remains outside this batch. The registry audit reports 13 affected dependency entries (12 high, one critical), while `--omit=dev` reports zero. That does **not** mean the browser bundle is unaffected: Axios is development-classified but bundled into browser JavaScript. Build-host tools and browser exposure are detailed in [npm-security-audit-2026-11-18.md](npm-security-audit-2026-11-18.md), with raw audit records in `docs/acceptance`. No dependency upgrade or forced audit fix was performed.

**10. Release decision**

**GO for this remediation batch against the stated acceptance gates.** All mandatory suites, workload scenarios, scientific/security regressions, browser checks, style/syntax/build checks, Compose validation, deployment/rollback rehearsals, and diff checks passed. This decision covers the tested configuration and retains the measured 21.3-second normal-job contention limitation; production capacity and latency commitments require environment-specific validation. npm dependency findings remain explicitly deferred to the separate dependency batch.

The base commit remains unchanged. Local release closure creates a new commit without amending history; no push or production deployment is authorized. Disposable application/test containers are stopped after evidence collection, with data volumes, failed-job evidence, pending rollback work, backups, and recovery images retained.


**11. Local release closure**

Direct upgrade from actual production commit `d15905ede05f0323f49ec2e1b5a79514282e6340`
is supported by the cumulative release. Production already contains the required
backup/smoke tools and shared volumes, and its migration file set matches
`58a097f`; no intermediate deployment is needed. This is source compatibility
confirmation; the real Docker rehearsal above began from `58a097f`.

Production shared `CACHE_STORE=file` is supported. A focused closure regression
passed (one test, eight assertions) for shared heartbeat/restart signals, actual
database enqueue and durable duplicate/backlog protection after cache clearing.
The 14 deployment tests were rerun successfully. The complete 642-test acceptance
runs above precede this additional test; the full suite was not repeated because
closure changed documentation, evidence and test harnesses only. No application
code changed during closure. The Phase I page still exposes no manual
calibration-period selection; existing automatic quantity-only selection remains.

Both feature flags remain disabled. Historical measurements, approved baselines,
SLO objectives and customized permissions are preserved by the additive/no-seeder
procedure. The measured 21.3-second normal-job contention is also documented in
[operation.md](operation.md). npm remediation remains outside this batch.

The intended manifest includes research documentation, reproducible guarded test
sources and sanitized acceptance evidence. Evidence excludes queue payloads,
credentials, database files, backups, container/image identifiers, host-specific
paths and raw container resource dumps. Aggregate metrics, test inventories,
non-sensitive checksums and event outcomes are retained. Browser test credentials
are supplied at runtime through `SVU_BROWSER_PASSWORD`; they are not committed.
