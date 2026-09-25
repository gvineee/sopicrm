<?php

namespace Database\Seeders\Modules;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Projects module permissions. Project-scoped access still requires an
 * active ProjectMembership in ProjectPolicy; these grants are only the
 * role half of that two-part authorization rule.
 */
class ProjectsPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'projects.viewAny',
            'projects.view',
            'projects.create',
            'projects.update',
            'projects.delete',
            'projects.status.change',
            'projects.wbs.manage',
            'projects.documents.manage',
            'projects.budget.view',
            // Audit A24: this permission was referenced by
            // App\Policies\ClientPolicy but never actually created, so
            // `create`/`update` there could not return true for anyone. The
            // client dropdown on the project form was therefore permanently
            // empty and no route existed to fill it.
            'projects.clients.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        $grants = [
            'owner' => $permissions,
            'project_manager' => [
                'projects.view',
                'projects.update',
                'projects.status.change',
                'projects.wbs.manage',
                'projects.documents.manage',
                'projects.budget.view',
                // A project manager creates and edits projects, so they must
                // be able to name the client a project is for; withholding
                // this is what left the dropdown unfillable.
                'projects.clients.manage',
            ],
        ];

        foreach ($grants as $roleName => $permissionNames) {
            /** @var Role $role */
            $role = Role::query()
                ->where('name', $roleName)
                ->whereNull('organization_id')
                ->firstOrFail();

            $role->givePermissionTo($permissionNames);
        }
    }
}
