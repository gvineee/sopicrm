<?php

namespace Database\Seeders\Modules;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Assets module's own permissions (docs/architecture.md §3.3, ASSETS-01).
 * `warehouse_keeper` (spec section 3's role table — "საწყობის
 * პასუხისმგებელი") is this domain's primary role. Self-service actions —
 * confirming your own receipt of an issued asset, and requesting a return of
 * something you currently hold — need NO permission grant at all (same
 * pattern as MyProfileController/MyDayController): the Policy checks
 * ownership (the acting user's own Employee record is the transaction's
 * `receiving_employee_id`), not a permission, mirroring how seeing your own
 * data never requires a permission grant in this codebase.
 */
class AssetsPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'assets.assets.view',
            'assets.assets.manage',
            'assets.custody.view',
            'assets.custody.manage',
            'assets.incidents.report',
            'assets.incidents.decide',
            // Stocktake (ASSETS-01 deferred remainder): deliberately two
            // permissions, not one — separation of duties between whoever
            // physically counts and whoever resolves/approves a variance.
            'assets.stocktakes.perform',
            'assets.stocktakes.approve',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $grants = [
            'owner' => $permissions,
            'warehouse_keeper' => $permissions,
            'project_manager' => [
                'assets.assets.view',
                'assets.custody.view',
                'assets.custody.manage',
                'assets.incidents.report',
                // A PM may perform a physical count during a site walkthrough
                // but does not hold final approval authority over a
                // variance — that separation-of-duties boundary stays with
                // owner/warehouse_keeper.
                'assets.stocktakes.perform',
            ],
            // Any employee may report damage/loss of an asset they hold —
            // deciding the outcome stays with warehouse_keeper/owner/PM.
            'employee' => ['assets.incidents.report'],
        ];

        foreach ($grants as $roleName => $permissionNames) {
            /** @var Role $role */
            $role = Role::query()->where('name', $roleName)->whereNull('organization_id')->firstOrFail();
            $role->givePermissionTo($permissionNames);
        }
    }
}
