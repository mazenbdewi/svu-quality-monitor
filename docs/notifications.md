# Notification engine

Confirmed incident and confirmed recovery events are emitted after the monitoring transaction commits. A listener creates one persistent delivery per enabled channel and queues it; Telegram and SMTP are never called from the incident transaction.

Telegram tokens are encrypted in `notification_settings` and are never exported or logged. Email recipients use Laravel's existing mail configuration. A service can disable all notifications with `notifications_enabled`.

Each delivery has a unique deduplication key: incident id + confirmed/resolved event + channel; SSL warnings use service, certificate expiry/subject identity, threshold and channel. Jobs retry three times with increasing backoff and retain only safe error messages.

SSL warnings use 30, 14, 7, 3 and 1 day thresholds. A new expiry/subject identity permits warnings after certificate renewal. Planned-maintenance failures create no incident and therefore no down message; an older confirmed incident may still emit its recovery message during maintenance. Flapping adds no reminders in this phase.

`system:check-background-health` runs every five minutes and sends one alert per queue/scheduler down cycle, then one recovery message. It can detect a stale worker while the scheduler runs. It cannot reliably alert on a complete scheduler stop, because that scheduler cannot run its own check; use an external watchdog or host/container health check for that case.
