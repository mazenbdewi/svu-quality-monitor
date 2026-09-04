# Planned maintenance windows

Planned maintenance is an excluded observation period, not uptime and not unplanned downtime. Windows are stored as UTC Laravel timestamps and are displayed using the application's configured timezone (currently UTC).

## Scope and scheduling

A window can target selected monitored services through a pivot table, or all services through `applies_to_all_services`. Its status is derived at read time: scheduled before `starts_at`, active from `starts_at` (inclusive) to `ends_at` (exclusive), and completed afterwards. End time must be later than start time.

The UI rejects overlapping windows for the same effective service set, including conflicts between an all-services window and a service-specific window. The overlap calculator still merges intervals defensively for legacy or race-created overlaps, so time is never subtracted twice.

Scheduled windows may be edited or deleted. Active windows may change their name, description, selected services, and a future end time; their start time is immutable. Completed windows cannot be edited or deleted in the normal UI, preserving historical check attribution and reliability results.

## Checks and incidents

The scheduler, queue job, and checker continue normally during maintenance. `ServiceCheckRunner` determines maintenance from the immutable `checked_at` timestamp, then stores both `is_during_maintenance` and `maintenance_window_id` on the check. Manual checks follow the same path.

When there is no open incident, a check attributed to maintenance never starts or advances failure confirmation. Any pending failure counters are safely cleared, so a failure that continues after the window ends begins a new sequence: the first post-maintenance failure is pending and the next one confirms the incident.

An incident that was confirmed before maintenance remains open. Failures during maintenance do not delete or auto-close it. Successful checks during maintenance still pass through the existing recovery confirmation state machine and may resolve it using the first recovery success as the recovery timestamp.

## Reliability

For each service and reporting period, maintenance intervals are clipped to the period and merged. Unplanned downtime is each incident overlap minus maintenance overlap within that incident. The calculation therefore preserves an incident's full historical record but removes only its planned-maintenance portion.

`planned_maintenance_minutes` is the excluded duration, and `observation_minutes = period_minutes - planned_maintenance_minutes`. Availability is:

`(observation_minutes - unplanned_downtime_minutes) / observation_minutes * 100`

MTTR, MTBF, and failure rate use the excluded maintenance observation time and unplanned downtime, so planned maintenance does not penalize those reliability metrics. Incident count remains the count of actual confirmed incidents intersecting the period.
