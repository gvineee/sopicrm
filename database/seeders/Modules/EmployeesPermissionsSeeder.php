<?php

namespace Database\Seeders\Modules;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Employees module's own permissions (docs/architecture.md §3.3). Creates
 * ONLY Permission records + role-permission attachments for roles
 * RbacBaseSeeder already created — never a Role record.
 *
 * Grants directly implement spec section 3's role table for this domain:
 *  - `hr`/`owner` manage the roster, teams, invites, documents, termination.
 *  - `finance`/`owner` manage rates (role table: "ფინანსისტი: ტარიფები");
 *    `hr` may VIEW rates (onboarding context) but not approve a change.
 *  - `employees.personal_id.view` and `employees.documents.view/manage` are
 *    deliberately narrower than the general roster-view permission (spec
 *    section 5: "პირადი ნომერი საჭიროების შემთხვევაში შეზღუდული წვდომით")
 *    — granted only to `hr`/`owner`, never blanket-attached to
 *    `employees.employees.view`.
 *  - `project_manager` explicitly does NOT receive `employees.rates.view`
 *    or `employees.rates.manage` (role table: "თანამშრომელთა პირადი
 *    ტარიფები დახურულია" — closed to PMs), proven by
 *    tests/Feature/Employees/RateHistoryPolicyTest.php.
 *  - `system_admin` receives NOTHING from this module (the role table gives
 *    system_admin users/devices/settings, not the HR employee roster).
 */
class EmployeesPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'employees.employees.view',
            'employees.employees.manage',
            'employees.personal_id.view',
            'employees.rates.view',
            'employees.rates.manage',
            'employees.documents.view',
            'employees.documents.manage',
            'employees.invites.manage',
            'employees.employment.terminate',
            'employees.teams.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $grants = [
            'owner' => $permissions, // full company overview, per role table
            'hr' => [
                'employees.employees.view',
                'employees.employees.manage',
                'employees.personal_id.view',
                'employees.rates.view',
                'employees.documents.view',
                'employees.documents.manage',
                'employees.invites.manage',
                'employees.employment.terminate',
                'employees.teams.manage',
            ],
            'finance' => [
                'employees.employees.view',
                'employees.rates.view',
                'employees.rates.manage',
            ],
        ];

        foreach ($grants as $roleName => $permissionNames) {
            /** @var Role $role */
            $role = Role::query()->where('name', $roleName)->whereNull('organization_id')->firstOrFail();
            $role->givePermissionTo($permissionNames);
        }
    }
}
