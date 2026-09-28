<?php

namespace Database\Seeders\Modules;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Payroll module's own permissions (docs/architecture.md §3.3). Finance owns
 * the whole money chain; `owner` has everything including the two
 * exception-grade permissions. `payroll.pay-runs.approve-own` (self-approval
 * exception, App\Domain\Payroll\Actions\ApprovePayRunAction) is registered
 * so an owner CAN be granted it, but per that Action's own docblock it is
 * NEVER auto-granted to any role here — an owner must extend it by hand,
 * as an explicit, separately-auditable step. `project_manager` has no
 * financial access at all (spec section 3: system admin/PM have no automatic
 * financial access).
 */
class PayrollPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'payroll.pay-periods.view',
            'payroll.pay-periods.manage',
            'payroll.pay-runs.view',
            'payroll.pay-runs.manage',
            'payroll.pay-runs.review',
            'payroll.pay-runs.approve',
            'payroll.pay-runs.approve-own',
            'payroll.adjustments.create',
            'payroll.adjustments.create-deduction',
            'payroll.advances.view',
            'payroll.advances.manage',
            'payroll.payments.view',
            'payroll.payments.manage',
            'payroll.daily-pay-policy.view',
            'payroll.daily-pay-policy.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $ownerGrants = array_values(array_diff($permissions, ['payroll.pay-runs.approve-own']));

        $grants = [
            'owner' => $ownerGrants,
            'finance' => $ownerGrants,
        ];

        foreach ($grants as $roleName => $permissionNames) {
            /** @var Role $role */
            $role = Role::query()->where('name', $roleName)->whereNull('organization_id')->firstOrFail();
            $role->givePermissionTo($permissionNames);
        }
    }
}
