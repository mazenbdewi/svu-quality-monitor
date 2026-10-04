# Phase I reactive eligibility correction

Review date: 2026-10-04, Asia/Damascus. Starting code: `research-pilot-v1.2`, commit `0ddfc502699209412de97ed8e2bf70602e14ba95`. No commit, push, deployment, production migration or Pilot start is part of this change.

## Demonstrated failures and diagnostic limits

Chrome exercised the actual `/admin/control-chart-baselines` page against an isolated database containing existing historical automatic observations. Before changing the form, the test selected a service, filled start, blurred start and waited for that request to finish, then filled a valid end and kept focus there. Both I/MR and P remained disabled. Blurring end then enabled both and returned the expected counts. All three datetime fields used `live(onBlur: true)`: edits were not sent independently while the field remained focused. A pending request from another field could happen to carry the edit, making the behaviour appear intermittent.

Separately, selecting a period outside that fixture's data disabled both choices, but Submit remained enabled and reported `The what do you want to monitor? field is required.` The action had no availability guard before checkbox validation and no condition disabling its submit button. This reproduces the impossible Required + Disabled state, without treating the absence of data in that deliberate empty test period as evidence about production.

The previous feature tests filled the form in bulk, rather than testing individual updates separated by completed requests. The previous browser workflow explicitly called `blur()` after filling end. Those tests proved eventual creation, but did not test while-focused updates, empty-population submit behaviour or switching between independently eligible populations. Historical fixture checks also had recording timestamps equal to their attempt timestamps; new tests use delayed persistence and second-level boundaries.

The deployed service/period/cutoff from the user's original attempt were not available for inspection. The user is collecting those details. The two defects above were reproduced in v1.2, but a claim that production observations are missing or incorrectly classified is not supported. No source/type mapping or timezone eligibility defect was demonstrated: valid-period counts became correct after blur, and new preview parity tests match unchanged R4A population capture.

## Form behaviour after correction

- Service selection reads the historical eligible P population as of now using the existing research scopes, maintenance exclusions and R4A created/updated-at guard. It shows the first and latest eligible observation in institution time. Both successes and failures can contribute to this range.
- Suggested start covers up to seven days ending at the latest eligible observation, bounded by the first available observation and rounded down to a minute. End is now, with second precision, and an empty cutoff still means end. This is a form suggestion, not a scientific stability decision. The researcher may change every period boundary. No snapshot or cutoff semantics changed.
- Start, end and cutoff now update after a 400 ms input debounce, without requiring blur. Datetime seconds are visible so the displayed cutoff matches the selected instant and real job recording delays can be expressed.
- Service/period changes clear stale metric selections and validation messages. Period changes do not reset the researcher's other period boundaries. Changing service suggests a new available period and clears cutoff.
- DateTimePicker's cast is used once: values accessed through `Get` and dehydrated submission data are already UTC. Suggestions passed through `Set` are UTC and are converted by the picker for display. Storage and `app.timezone` remain UTC; tests use Asia/Damascus.
- Eligible counts appear next to each checkbox. No latency does not disable P when automatic binary observations are eligible.
- Create is disabled when no population is eligible or required period inputs are incomplete/invalid. An action guard also blocks Enter/direct submissions before required-checkbox validation and displays a contextual explanation. The checkbox remains required when an eligible choice actually exists.
- Eligibility preview only reads observations/counts; it does not build snapshots or a potentially huge hourly bucket series for partially typed dates. It reuses the same R1/R3 scopes/maintenance exclusions and R4A record-time guards. Final creation still uses the original R4A engine and outer atomic transaction.

## Verification

New `PhaseOneReactiveEligibilityTest` covers independent service/date/cutoff updates, suggested ranges, eligible counts, historical API checks with recording delays, midnight in Damascus, P-only services, no-data services, direct-submit protection, recovery from an empty period without remounting, and equality with R4A capture counts. Previous workflow tests now select metrics after period updates, matching the actual UI, and assert contextual blocking instead of impossible required errors when no choice exists.

Chrome verification passed: valid service defaults; counts while dates remain focused; cutoff at one second before/at record availability; switching services; no-data/empty-period messages and disabled submit; no misleading required message after Enter; creation of I + MR + P; P-only creation; no JavaScript page errors. Test scripts, SQLite fixtures and screenshots remain in `/tmp/phase-one-browser`, outside the repository. No real university endpoint was probed.

No new migrations. No changes to research eligibility scopes, `BaselinePopulation`, `BaselineStatistics`, `PhaseOneBaselineService`, `BaselineConfiguration`, the approval lifecycle, immutable historical models, SPC formulas or the Phase II evaluator. The pre-existing untracked release archive is left untouched.

Final checks: `php artisan test` — **499 passed, 2709 assertions, zero failures** (69.34 seconds). `./vendor/bin/pint --test` passed. `git diff --check` passed. The temporary local PHP browser-test server was stopped after verification.
