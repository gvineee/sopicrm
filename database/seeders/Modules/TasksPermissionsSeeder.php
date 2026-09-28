<?php

namespace Database\Seeders\Modules;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Tasks module permissions (spec section 10). Project-scoped access still
 * requires an active ProjectMembership via ProjectPolicy's pattern, reused
 * by App\Policies\TaskPolicy::hasProjectAccess() — these grants are only the
 * role half of that two-part rule. An employee's OWN task access
 * (accountable owner / assignee / brigade member) never depends on any of
 * these permissions — see TaskPolicy::isPerformer().
 */
class TasksPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'tasks.tasks.view',
            'tasks.tasks.manage',
            'tasks.tasks.accept',
            'tasks.tasks.cancel',
            'tasks.tasks.reopen',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        $grants = [
            'owner' => $permissions,
            'project_manager' => $permissions,
            'foreman' => ['tasks.tasks.view'],
        ];

        foreach ($grants as $roleName => $permissionNames) {
            /** @var Role|null $role */
            $role = Role::query()
                ->where('name', $roleName)
                ->whereNull('organization_id')
                ->first();

            $role?->givePermissionTo($permissionNames);
        }
    }
}
