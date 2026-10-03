# Phase I creation and researcher workflow review

Review date: 2026-10-03 (Asia/Damascus). No deployment, production migration, commit, push or Pilot start was performed.

## Reproduction and scope of the diagnosis

The starting working tree was clean at `55dbe625f5570f98f729f9e34347478c0392def8` (`research-pilot-v1.1`). A Chrome/Playwright session exercised the actual `/admin/control-chart-baselines` page through login, the header creation action, service selection, native datetime inputs and Submit. An isolated SQLite database and inactive synthetic test service were used, with no HTTP checks against real services. A fixture administrator was created only in that database; baseline creation itself was performed in the browser.

With historical local inputs 2026-09-26 03:00–07:00 Asia/Damascus, the original UI successfully created an I-chart Draft with four eligible observations and UTC bounds 00:00–04:00. The service selector persisted its ID, authorization passed, the action ran and the snapshot was stored. Therefore an unconditional inability to create any baseline was **not reproduced** in the repository version.

A specific silent failure **was reproduced**: enter a future period (2099-09-26), then submit. No baseline is created, the modal stays open, and no explanatory message appears. `PhaseOneBaselineService::invalid()` throws a validation error under `baseline`; the action has no matching visible field and does not map the error into its mounted schema or send a notification. This is the demonstrated cause of the silent rejection, not evidence that every reported failed attempt used future dates.

The existing local Laravel log did not establish a separate baseline-specific exception. Read-only inspection of the running local Docker app at `/var/www/html` found no `ControlChartBaselines` resource, no `PhaseOneBaselineService`, and no R4A/timezone migration files (the listed migrations ended with the older September 4 files). It uses an older image, not the current checkout. This explains why that local image cannot provide the repository's Phase I workflow, but does not identify the user's remote server version. No container was rebuilt, restarted or deployed.

## Changes

- Keep the dedicated creation action; `canCreate() = false` continues to disable generic Eloquent creation that would bypass R4A.
- One form selects a service, historical period, optional cutoff, and the aspects to study. I/MR is one checkbox creating separate I and MR records; P is a separate checkbox. P aggregation is hourly by default, with daily available as before.
- The optional cutoff defaults to end, matching R4A. End is exclusive. Filament's DateTimePicker state cast already converts local inputs to UTC, including values accessed by `Get`; preview and submission use that same conversion.
- Services are searchable and are not hidden by a new type/eligibility filter. Disabled aspect options remain visible with explanations and eligible observation counts for the selected period. Preview delegates to the existing R4A population capture; submission rechecks the captured samples. Manual, maintenance, diagnostic/synthetic, unsupported check types and late/modified observations remain excluded under the original policy.
- Empty populations are rejected visibly by this UI orchestration, without changing the underlying R4A creation method. Limited non-empty populations can still produce Drafts with a warning; existing review/approval sufficiency rules are unchanged.
- `PhaseOneBaselineSubmission` wraps the original R4A calls in one outer transaction and holds the service lock. A later failure rolls back all newly created components, sample snapshots and audit events.
- Duplicate UI submission is rejected if any requested component already has a Draft/Reviewed/Approved record with the same service, chart, start/end, cutoff, timezone and aggregation. The whole submission is rejected, with no partial group. Historical rows are preserved. Rejected/retired references do not block a replacement; original backend version allocation remains unchanged.
- Domain validation is shown as an error notification and mapped to the mounted action's field paths. Unexpected exceptions are logged and a localized nontechnical failure notification is displayed. Existing permission checks are retained, including server-side checks after role revocation.
- Arabic/English explanations cover I, MR, P, `!is_success || is_slow`, Phase I, Baseline and the Draft → data review → Reviewed → Approved → Phase II sequence. Help is expandable and available in titles; creation never approves automatically. The list is grouped by service and preserves each version/detail link.
- C/U remain in the existing Control Charts implementation. They were never valid R4A baseline types and are not offered as baseline creation options.

## Evidence and regression coverage

Real browser checks after the change passed for I/MR only, P only, all three records, visible duplicate rejection, visible future-period explanation, and Arabic combined creation. No JavaScript page errors were observed. Screenshots and browser fixtures were kept outside the repository under `/tmp/phase-one-browser`; these are temporary local evidence, not deployment or research observations.

`PhaseOneCreationWorkflowTest` adds 19 feature cases covering mounted-action submission, HTTP page access, selector options, each aspect combination, UTC/cutoff semantics, duplicates, transaction rollback (including samples and audit), validation, disabled eligibility explanations, authorization/revocation, both languages, approved history preservation and comparison of parameters/membership/coverage with the unchanged R4A engine. The original lifecycle UI test now selects the I/MR pair.

No changes were made to `BaselinePopulation`, `BaselineStatistics`, `PhaseOneBaselineService`, `BaselineConfiguration`, SPC calculators, research eligibility scopes, immutable historical models or the Phase II evaluator. No migration is required by this fix. Any previously pending release migrations remain a separate deployment concern.

Final verification: `php artisan test` — **493 passed, 2631 assertions, zero failures** (76.71 seconds). `./vendor/bin/pint --test` passed. `git diff --check` passed. The temporary local browser test server was stopped after verification; existing Docker services were not changed.

## Changed files

- `app/Filament/Resources/ControlChartBaselines/ControlChartBaselineResource.php`
- `app/Filament/Resources/ControlChartBaselines/Pages/ListControlChartBaselines.php`
- `app/Services/Baselines/PhaseOneBaselineSubmission.php`
- `resources/views/filament/baselines/creation-help.blade.php`
- `resources/views/filament/baselines/list.blade.php`
- `lang/ar/baseline_creation.php`
- `lang/en/baseline_creation.php`
- `tests/Feature/PhaseOneCreationWorkflowTest.php`
- `tests/Feature/PhaseOneBaselineTest.php`
- `docs/phase-one-creation-fix.md`
