# Institution calendar and daily scheduling

Post-freeze correction requested on 2026-09-27. `research-pilot-v1` remains unchanged; this working-tree change is not committed, deployed or used to start Pilot.

`institution_settings.timezone` is the single current institution calendar, defaulting to `Asia/Damascus`. Institution Settings offers validated PHP/IANA identifiers, a local-time preview and a measurement-configuration warning. Only `institution.manage` can save changes. `InstitutionTimezone` reads the committed database value without a persistent process cache. `INSTITUTION_TIMEZONE`, `SPC_ANALYSIS_TIMEZONE`, `monitoring.institution_timezone` and `monitoring.spc.analysis_timezone` are no longer configuration sources; legacy environment values are ignored. The web UI never writes `.env`. `app.timezone` and stored timestamps remain UTC.

Saving a changed timezone records `institution.timezone_changed`, old/new timezone, actor and UTC audit timestamp within the settings transaction. During a study, record this as a measurement-phase boundary and review affected periods; do not silently pool observations from different calendars.

| Job | Institution time | UTC display for Damascus UTC+03:00 |
|---|---|---|
| Reliability | 00:10 | 21:10 on preceding UTC date |
| SLA | 00:20 | 21:20 on preceding UTC date |
| SPC | 00:25 | 21:25 on preceding UTC date |
| Backup | 02:00 | 23:00 on preceding UTC date |
| Cleanup | 03:00 | 00:00 |

All five daily events have an explicit institution timezone. Minute/five-minute events and overlap guards are unchanged. Laravel's default `schedule:list` renders expressions in the application timezone (UTC); `--timezone=Asia/Damascus` shows the local target times. Listing does not execute jobs, but can inspect cache locks; when inspecting outside Docker with no database hostname available, use a process-only `CACHE_STORE=array` override.

Reliability previously used UTC for implicit yesterday and calendar boundaries. It now selects yesterday in institution time, builds daily/weekly/monthly boundaries there and converts both instants to UTC before invoking the unchanged calculator. Explicit `--date` uses the institution calendar; a timestamp with an offset is converted there before finding boundaries. Existing weekly/monthly default selection remains the period containing yesterday, without introducing a different completed-period policy.

Example: at 2026-09-27 00:10 Damascus (2026-09-26 21:10 UTC), yesterday is September 26. Its half-open UTC interval is [2026-09-25 21:00:00, 2026-09-26 21:00:00). A check exactly at the upper boundary belongs to the next day.

SLA has always been monthly despite daily execution. Previously it selected the current UTC month. It now selects the month containing institution-local yesterday unless `--date` is given. Thus October 1 at 00:20 Damascus finalizes September; October 2 at 00:20 updates October. Monthly start/end are built locally then converted to UTC. The existing endOfMonth convention and calculator clipping at now, maintenance handling, availability and error-budget formulas remain unchanged; this correction does not turn SLA into a daily metric or add a new cutoff policy. September's persisted boundaries are August 31 21:00:00 UTC through September 30 20:59:59 UTC.

SPC already selected the previous local period and returned UTC bounds. Its calculation and bucket rules are unchanged. Backup/cleanup changes affect execution times only.

No stored historical results are rewritten. A newly requested local-calendar result can coexist with an old UTC-calendar row because the period bounds differ. Do not pool these overlapping records as one comparison period; record the measurement-phase boundary and deploy before Pilot collection. The additive migration `2026_09_27_000001_add_institution_timezone.php` adds the institution setting and nullable `analysis_timezone` columns to Reliability/SLA metrics. Existing metric timezone values remain null (legacy UTC interpretation), without backfill. New calculated results retain their calculation calendar, and recalculation preserves existing stored metadata. This migration has only been exercised in test databases, not applied to production.

Regression tests cover local-vs-UTC midnight with boundary checks, explicit dates and offset timestamps, weekly/monthly Reliability boundaries, month/year transitions for SLA, scheduler due times and unchanged recurring entries. The release configuration snapshot now records institution timezone and the corrected daily scheduler timezones.

## Runtime, display and historical integrity

Docker `compose.yaml` runs `php artisan schedule:work`. The installed Laravel worker starts a fresh `schedule:run` process each minute. Schedule registration resolves the database timezone lazily when the Schedule service is requested; it does not connect to the database just to bootstrap unrelated Artisan commands. A saved timezone therefore affects the next scheduler cycle without a restart or config-cache rebuild. Independent PHP process tests verify that a database change changes daily expressions while minute/five-minute entries remain unchanged. This does not constitute a Docker deployment test.

Install the new application code and apply the additive migration through the separate authorized deployment procedure before using the setting. Normal release worker restarts are distinct from subsequent UI timezone changes, which require no restart. Do not change timezone near a daily trigger: moving the calendar (or DST) can skip/repeat local scheduled times; overlap guards do not provide once-per-local-day guarantees. Already running calculations retain their selected period. No automatic backfill or rescheduling is performed.

Filament date/time display and input conversion use the institution calendar with UTC persistence. Report date filters translate local day boundaries into UTC query bounds, including 23/25-hour DST days. Human-facing historical chart/metric periods use the timezone stored with the result, falling back to UTC for legacy records. Minitab raw timestamps and research JSON retain UTC instants for reproducibility; exported metric metadata includes the analysis timezone.

Approved baselines and their saved calendars are never rewritten. Phase II computations based on an existing baseline continue to use its frozen calendar; an institution change does not approve or create a replacement reference. Researchers must review the measurement boundary and suitability of the existing reference. Newly created references default to the institution calendar.

Validation covers defaults, IANA validation, permissions, audit, UTC persistence, UI conversion, local report filters, midnight/month/year boundaries, historical results, approved baselines, migration preservation and scheduler reload across separate processes. No Pilot, production migration, commit or push is performed by this change.
