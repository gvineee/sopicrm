<?php

namespace Database\Seeders\Modules;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Timesheets module's own permissions (docs/architecture.md §3.3).
 * `project_manager` runs day-to-day generation/submission/adjustment
 * requests on site; final approve/lock and adjustment decisions stay with
 * `finance`/`owner` — self-approval is additionally blocked at the Action
 * level (App\Domain\Timesheets\Support\SelfApprovalGuard) regardless of role.
 */
class TimesheetsPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'timesheets.timesheets.view',
            'timesheets.timesheets.generate',
            'timesheets.timesheets.submit',
            'timesheets.timesheets.approve',
            'timesheets.adjustments.view',
            'timesheets.adjustments.request',
            'timesheets.adjustments.decide',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $grants = [
            'owner' => $permissions,
            'project_manager' => [
                'timesheets.timesheets.view',
                'timesheets.timesheets.generate',
                'timesheets.timesheets.submit',
                'timesheets.adjustments.view',
                'timesheets.adjustments.request',
            ],
            'finance' => [
                'timesheets.timesheets.view',
                'timesheets.timesheets.approve',
                'timesheets.adjustments.view',
                'timesheets.adjustments.decide',
            ],
        ];

        foreach ($grants as $roleName => $permissionNames) {
            /** @var Role $role */
            $role = Role::query()->where('name', $roleName)->whereNull('organization_id')->firstOrFail();
            $role->givePermissionTo($permissionNames);
        }
    }
}
