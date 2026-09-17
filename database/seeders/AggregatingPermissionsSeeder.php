<?php

namespace Database\Seeders;

use Database\Seeders\Modules\AuthPermissionsSeeder;
use Database\Seeders\Modules\DailyJournalPermissionsSeeder;
use Database\Seeders\Modules\DevicesPermissionsSeeder;
use Database\Seeders\Modules\EmployeesPermissionsSeeder;
use Database\Seeders\Modules\ProjectsPermissionsSeeder;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Foundation-owned (docs/architecture.md §3.3 / DEC-020). Runs every
 * `database/seeders/modules/*PermissionsSeeder.php` in a fixed, documented
 * order. A module adds its own file + a new numbered entry below; it never
 * edits this file's dispatch logic, only appends to the ORDER list.
 *
 * Load order:
 *   1. AuthPermissionsSeeder — must run first: every other module's
 *      permissions attach to roles this module's RbacBaseSeeder created,
 *      and later modules may reference Auth's `finance.access`-style
 *      pattern as their own template.
 *   2. DailyJournalPermissionsSeeder — spec section 11. Depends only on
 *      RbacBaseSeeder's roles, no ordering dependency on Auth's grants.
 *   3. EmployeesPermissionsSeeder — spec section 5. Depends only on
 *      RbacBaseSeeder's roles.
 */
class AggregatingPermissionsSeeder extends Seeder
{
    /**
     * @var list<class-string<Seeder>>
     */
    private const ORDER = [
        AuthPermissionsSeeder::class,
        DailyJournalPermissionsSeeder::class,
        EmployeesPermissionsSeeder::class,
        DevicesPermissionsSeeder::class,
        ProjectsPermissionsSeeder::class,
    ];

    public function run(): void
    {
        $this->call(RbacBaseSeeder::class);

        foreach (self::ORDER as $seederClass) {
            $this->call($seederClass);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
