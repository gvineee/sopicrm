<?php

namespace Database\Seeders\Modules;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Contractors module's own permissions (docs/architecture.md §3.3). Creates
 * ONLY Permission records + role-permission attachments for roles
 * RbacBaseSeeder already created — never a Role record.
 *
 * `project_manager` runs the site day-to-day (assigns contractors, submits
 * and reviews their acts) but never approves a contract or records a
 * payment — those stay with `finance`/`owner`, mirroring the same
 * split Employees uses for rates ("თანამშრომელთა პირადი ტარიფები
 * ფინანსისტს/owner-ს ეკუთვნის").
 */
class ContractorsPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'contractors.contractors.view',
            'contractors.contractors.manage',
            'contractors.contracts.manage',
            'contractors.contracts.approve',
            'contractors.assignments.manage',
            'contractors.acts.submit',
            'contractors.acts.review',
            'contractors.payments.view',
            'contractors.payments.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $grants = [
            'owner' => $permissions,
            'project_manager' => [
                'contractors.contractors.view',
                'contractors.contractors.manage',
                'contractors.contracts.manage',
                'contractors.assignments.manage',
                'contractors.acts.submit',
                'contractors.acts.review',
            ],
            'finance' => [
                'contractors.contractors.view',
                'contractors.contracts.approve',
                'contractors.payments.view',
                'contractors.payments.manage',
            ],
        ];

        foreach ($grants as $roleName => $permissionNames) {
            /** @var Role $role */
            $role = Role::query()->where('name', $roleName)->whereNull('organization_id')->firstOrFail();
            $role->givePermissionTo($permissionNames);
        }
    }
}
