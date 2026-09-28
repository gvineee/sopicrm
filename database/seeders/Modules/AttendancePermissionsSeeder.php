<?php

namespace Database\Seeders\Modules;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Attendance module's own permissions (docs/architecture.md §3.3). Creates
 * ONLY Permission records + role-permission attachments for roles
 * RbacBaseSeeder already created — never a Role record.
 *
 * Anomaly resolution and shift-template/assignment management stay with
 * `project_manager`/`owner` (site-level supervisory decisions); `finance`
 * only needs read access here since payroll consumes closed sessions, not
 * the raw attendance workflow itself.
 */
class AttendancePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'attendance.shift_templates.view',
            'attendance.shift_templates.manage',
            'attendance.shift_assignments.view',
            'attendance.shift_assignments.manage',
            'attendance.sessions.view',
            'attendance.sessions.manage',
            'attendance.anomalies.view',
            'attendance.anomalies.resolve',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $grants = [
            'owner' => $permissions,
            'project_manager' => $permissions,
            'finance' => [
                'attendance.shift_templates.view',
                'attendance.shift_assignments.view',
                'attendance.sessions.view',
                'attendance.anomalies.view',
            ],
        ];

        foreach ($grants as $roleName => $permissionNames) {
            /** @var Role $role */
            $role = Role::query()->where('name', $roleName)->whereNull('organization_id')->firstOrFail();
            $role->givePermissionTo($permissionNames);
        }
    }
}
