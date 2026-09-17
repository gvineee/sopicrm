<?php

namespace Database\Seeders\Modules;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Auth module's own permissions (docs/architecture.md §3.3): naming
 * convention `<module>.<resource>.<action>`. Creates ONLY Permission
 * records and role-permission attachments for roles RbacBaseSeeder already
 * created — never a Role record itself.
 *
 * Grants below directly implement the spec section 3 role table AND its
 * explicit carve-out: "სისტემურ ადმინისტრატორს ფინანსური წვდომა
 * ავტომატურად არ მიენიჭოს" (system admin does NOT automatically get
 * financial access) — `finance.access` is granted to `owner` and `finance`
 * only, never `system_admin`, and the `access-financial-data` Gate
 * (App\Providers\Auth\AuthModuleServiceProvider) /
 * tests/Feature/Auth/RolePermissionEnforcementTest.php prove this at the
 * Gate level, not just by omission here.
 */
class AuthPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'auth.users.view',
            'auth.users.manage',
            'auth.roles.assign',
            'auth.tokens.issue-machine',
            'audit.events.view',
            'audit.events.export',
            'projects.memberships.manage',
            // Not a real financial-domain permission yet (Payroll/Finance
            // modules are out of this pass's scope) — a minimal stand-in
            // used to prove, at the Policy/Gate layer, the spec's explicit
            // "system admin never gets financial access automatically"
            // rule so later financial-domain permissions can copy this
            // exact grant pattern.
            'finance.access',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $grants = [
            'owner' => [
                'auth.users.view', 'auth.users.manage', 'auth.roles.assign',
                'auth.tokens.issue-machine', 'audit.events.view', 'audit.events.export',
                'projects.memberships.manage', 'finance.access',
            ],
            'system_admin' => [
                'auth.users.view', 'auth.users.manage', 'auth.roles.assign',
                'auth.tokens.issue-machine', 'audit.events.view',
                // deliberately NOT 'finance.access', NOT 'audit.events.export'
            ],
            'hr' => ['auth.users.view'],
            'finance' => ['finance.access'],
            'project_manager' => ['projects.memberships.manage'],
        ];

        // `firstOrFail()` below is the actual safety net against a
        // rename/typo in $grants: a role name that doesn't exist in
        // RbacBaseSeeder::ROLES throws immediately at seed time instead of
        // silently granting nothing.
        foreach ($grants as $roleName => $permissionNames) {
            /** @var Role $role */
            $role = Role::query()->where('name', $roleName)->whereNull('organization_id')->firstOrFail();
            $role->givePermissionTo($permissionNames);
        }
    }
}
