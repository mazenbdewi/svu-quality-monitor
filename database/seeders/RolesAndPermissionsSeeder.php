<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = ['services.view', 'services.create', 'services.update', 'services.delete', 'services.check_now', 'incidents.view', 'incidents.acknowledge', 'maintenance.view', 'maintenance.create', 'maintenance.update', 'maintenance.delete', 'reports.view', 'reports.generate', 'sla.view', 'sla.manage', 'notifications.view', 'notifications.manage', 'institution.view', 'institution.manage', 'operations.view', 'reliability.view', 'control_charts.view', 'users.view', 'users.create', 'users.update', 'users.delete', 'roles.manage', 'audit.view', 'system.manage'];
        $permissions = [...$permissions, 'control_charts.baselines.manage', 'backups.view', 'backups.create', 'backups.restore'];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $all = Permission::all();
        Role::findOrCreate('super_admin', 'web')->syncPermissions($all);
        Role::findOrCreate('administrator', 'web')->syncPermissions($all->whereNotIn('name', ['roles.manage', 'system.manage', 'backups.restore']));
        Role::findOrCreate('operator', 'web')->syncPermissions(['services.view', 'services.check_now', 'incidents.view', 'incidents.acknowledge', 'maintenance.view', 'maintenance.create', 'maintenance.update', 'maintenance.delete', 'reports.view', 'reports.generate', 'sla.view', 'notifications.view', 'operations.view', 'reliability.view', 'control_charts.view']);
        Role::findOrCreate('viewer', 'web')->syncPermissions(['services.view', 'incidents.view', 'maintenance.view', 'reports.view', 'sla.view', 'reliability.view', 'control_charts.view', 'audit.view']);
    }
}
