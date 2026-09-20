# Human administrative audit coverage (8F-3)

Audit entries are emitted at intentional operations, never by model observers. Direct model writes by monitoring engines, seeders, factories and scheduled calculations do not create human audit entries.

| Operation | Integration point | Events |
| --- | --- | --- |
| Service create/edit | CreateMonitoredService / EditMonitoredService, AuditsAdministrativeChanges | service.created, service.updated, service.enabled, service.disabled, service.sla_updated, service.monitoring_config_updated |
| Service deletion | Edit delete action and list bulk delete, AdministrativeAudit::delete | service.deleted (one per deleted resource) |
| Maintenance create/edit | CreateMaintenanceWindow / EditMaintenanceWindow, AuditsAdministrativeChanges | maintenance.created, maintenance.updated |
| Maintenance deletion | List and edit delete actions, AdministrativeAudit::delete | maintenance.deleted |
| User administration | UserAdministrationService::create/update; activate/deactivate/syncRoles delegate to update | user.created, user.updated, user.activated, user.deactivated, user.role_changed, user.password_changed_by_admin |
| Notification settings | NotificationSettingsPage::save | notification.settings_updated |
| Manual notification tests | NotificationSettingsPage::queueTest | notification.telegram_test_requested, notification.email_test_requested |
| Manual delivery retry | NotificationDeliveryRetryService::retry with an explicit actor from the Filament action | notification.retry_requested |
| Institution settings | InstitutionSettingsPage::save | institution.updated |
| Report generation | ReportsPage::export after Excel download preparation or successful PDF rendering | report.generated |
| Incident acknowledgement | IncidentAcknowledgementService | incident.acknowledged |
| Login | Existing Login listener | user.logged_in |

## Diffs and event granularity

AdministrativeAudit centrally selects fields by model class, computes changed fields and builds before/after. It does not copy full raw model attributes. Service changes involving multiple categories produce one service.updated entry with SLA/configuration markers; isolated state, SLA or monitoring configuration changes use their specific events. User profile, role, activation and password changes have distinct semantics and produce one entry per changed category. A role change through syncRoles does not also emit a generic user.updated event. Saves with no actual differences emit nothing.

Service allowlist: name, check_type, is_active, expected_status_code, check_interval_minutes, warning_response_ms, critical_response_ms, sla_enabled, sla_target_percent, notifications_enabled, failure_confirmation_count, recovery_confirmation_count. Timeout and other check_config values remain excluded. URL, expected_keyword, category and notes changes are represented by other_configuration_changed, with no values copied.

Maintenance: name, starts_at, ends_at, applies_to_all_services and sorted service IDs. Description changes use a marker. User: name, email, roles and activation status. Notification: enabled flags; recipient and destination changes use markers (addresses/chat IDs are not stored). Institution: name; logo changes use a marker because a URL may contain signed credentials.

Sensitive comparisons use transient fingerprints in local/protected state. Fingerprints themselves never enter the audit payload. check_config gets only sensitive_monitoring_configuration_changed. Telegram token changes get telegram_token_action: configured/replaced/removed. Removing a stored token is explicit; a blank token field still preserves it, and removal while Telegram remains enabled fails validation. User passwords and hashes are never included; a successful password change records password_changed=true.

## Sanitization and context

AuditLogger sanitizes before/after/context recursively, case-insensitively, ignoring separators so apiKey and X-API-Key match. Password, token, secret, credentials, authorization, cookie, API-key, private-key, remember-token, check_config, headers and request/body/payload containers are redacted. Arbitrary objects are not serialized. Only strictly typed password_changed and enumerated telegram_token_action markers bypass their respective redaction rule.

Call sites supply explicit allowlisted metadata and static localized descriptions, not request payloads or exception messages. Report audit stores only type, format, language and period, never report content. SMTP/mail API credentials are runtime configuration; NotificationSetting does not store them. The audit logger does not inspect that configuration.

Explicit actors from user/incident/retry services override the ambient authenticated user; otherwise the current actor is used. With neither present actor_id is null. There is no fallback administrator. The Login listener passes the event user explicitly and retains IP/User Agent. last_login_at is assigned with forceFill for this trusted server-generated field, which is intentionally not user-mass-assignable.

## Atomicity and exclusions

Service and maintenance create/edit use Filament transactions encompassing record, relationship changes and audit hooks. Deletes, user changes, notification/institution saves and acknowledgements use database transactions. Notification test creation, after-commit job dispatch and its audit are in one transaction. Retry audit is inside the existing retry transaction, and its job is dispatched after commit. A denied, invalid, rolled-back or no-op operation does not get a success entry. A PDF is rendered before report.generated; Excel download preparation must return successfully before its entry. This represents generation, not confirmation that a browser received all bytes.

No audit is emitted for automatic checks, heartbeats, scheduled reliability/SLA/control-chart calculations, automatic incident confirmation/recovery, automatic SSL notifications or retry calls without an explicit human actor. Logout remains outside this phase.

The sanitizer is key-based defense in depth, not content-based secret discovery in arbitrary human text. Safe fields such as names and descriptions must not be repurposed as credential storage. Database concurrency/Docker verification remains outside 8F-3.

R1 adds an explicit `endpoint_changed` boolean to service audit context. Endpoint values remain excluded; interval, performance thresholds and check type retain their existing before/after audit coverage.
