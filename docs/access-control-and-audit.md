# Access control and audit

RBAC uses `spatie/laravel-permission`. Super Admin receives a central Gate bypass. Administrator manages normal users and operational settings; Operator monitors services, acknowledges incidents, manages maintenance and generates reports; Viewer is read-only.

For an existing installation, seed roles then explicitly grant the existing administrator:

`php artisan users:grant-super-admin user@example.com`

The command is idempotent and activates only that existing account. Never grant every historical account automatically.

`UserAdministrationService` prevents a system with no active Super Admin and prevents non-Super Admins from modifying Super Admin accounts. Users are deactivated rather than deleted.

Audit logs intentionally exclude automatic checks and heartbeats. The central sanitizer recursively replaces secrets such as passwords, tokens, authorization headers, API keys, cookies and raw monitoring configuration.

Incident acknowledgement records who acknowledged a still-open incident; it never resolves it.
