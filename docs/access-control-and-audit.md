# Access control and audit

RBAC uses `spatie/laravel-permission`. Super Admin receives a central Gate bypass. Administrator manages normal users and operational settings; Operator monitors services, acknowledges incidents, manages maintenance and generates reports; Viewer is read-only.

For an existing installation, seed roles then explicitly grant the existing administrator:

`php artisan users:grant-super-admin user@example.com`

The command is idempotent and activates only that existing account. Never grant every historical account automatically.

`UserAdministrationService` prevents a system with no active Super Admin and prevents non-Super Admins from modifying Super Admin accounts. Users are deactivated rather than deleted.

Audit logs intentionally exclude automatic checks and heartbeats. The central sanitizer recursively replaces secrets such as passwords, tokens, authorization headers, API keys, cookies and raw monitoring configuration.

Incident acknowledgement records who acknowledged a still-open incident; it never resolves it.

## Docker/MySQL verification — 2026-09-07

The five running containers are healthy at the service/process level; MySQL reports 8.4.8. `/admin` redirects to login, which returns 200, and scheduler/queue heartbeats are healthy. However, the deployed application image predates RBAC/Audit: the operational database has one user and lacks the roles table, `is_active`, audit tables and incident acknowledgement fields. Active Super Admin status cannot be established. Read-only migration status using the current code confirms three pending migrations: `2026_09_04_131135_create_permission_tables`, `2026_09_04_131136_add_user_security_and_audit_logs`, and `2026_09_04_131137_add_incident_acknowledgement`. No migration, RBAC seeder or account grant was run against that database.

Current working-tree code was tested using the existing PHP image with a read-only code mount and a separate temporary MySQL schema. Migrations succeeded there. Seeder counts were identical after both runs: 4 roles, 29 permissions and 79 role-permission links; user records and role assignments were unchanged. Last-Super-Admin guards, an actual second-connection InnoDB lock timeout, rollback after an audit insert, audit JSON casts, panel access, Gate bypass and acknowledgement history preservation passed. The `acknowledged_by` foreign key uses `ON DELETE SET NULL`. Transactional fixtures were rolled back, and the temporary schema and its grant were removed.

This verifies the current code on MySQL, not deployment of that code to the operational stack. Stage 8 cannot be closed until the current code and pending migrations are deployed through an explicitly authorized account-preservation path. Identify the existing administrator account first; do not guess its email or run the general DatabaseSeeder. Once the required code/schema/roles are available, the explicit grant command remains:

`php artisan users:grant-super-admin user@example.com`

The concurrency probe proves row-lock exclusion using two MySQL connections; it is not a load or multi-worker stress test. No images were rebuilt and no operational users were modified during verification.
