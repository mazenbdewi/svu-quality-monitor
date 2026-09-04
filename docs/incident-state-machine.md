# Incident Confirmation and Recovery

The operational state belongs to the monitored service; an incident row is created only after an outage is confirmed.

- **healthy:** no pending functional failure.
- **pending_failure:** failures are being counted, with no incident yet.
- **down:** the failure confirmation threshold was reached and one incident is open.
- **recovering:** an open incident has one or more successful checks, but recovery is not yet confirmed.

The default thresholds are two consecutive functional failures and two consecutive successful checks. They can be adjusted per service. Manual and automatic checks use the same state machine because both are real measurements; their source remains stored on the check.

`started_at` is the first failure in the sequence, while `confirmed_at` is the check which meets the failure threshold. `recovery_started_at` is held on the service until recovery is confirmed; the incident's `resolved_at` and `ended_at` use that first successful check, not the second confirmation check. This preserves historical downtime while still requiring confirmation before closing the incident.

Performance warning/critical and SSL near-expiry checks are successful checks and never increase failure counters. Updates run inside a database transaction with a row lock on the service. The last processed check ID makes repeated processing idempotent. Repeated state transitions are counted in a configurable 15-minute window; four transitions flag the service as flapping without changing incident workflow.
