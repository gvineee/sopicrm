<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Foundation-owned (docs/architecture.md §3.3 / DEC-020): creates the 11
 * roles from spec section 3 as spatie/laravel-permission Role records. Every
 * seeded role here is a GLOBAL template (`organization_id` null — see
 * docs/data-model.md "roles"): the fixed role catalog is shared reference
 * data across every tenant, not something each organization defines for
 * itself. A user's role ASSIGNMENT (model_has_roles row) is what carries the
 * organization_id, scoping which org that grant applies in — see
 * App\Models\User::assignRole() usage in tests/seeders and
 * App\Http\Middleware\SetCurrentOrganization (sets the spatie "team" context
 * from the authenticated user's current_organization_id before any
 * hasRole()/can() check runs).
 *
 * No module seeder may edit this file — see
 * database/seeders/AggregatingPermissionsSeeder.php.
 */
class RbacBaseSeeder extends Seeder
{
    /**
     * Slug => Georgian label (spec section 3's exact role table), kept as a
     * comment-adjacent map so any later reader can verify the mapping
     * without cross-referencing the spec file.
     *
     * owner                 => მფლობელი/დირექტორი
     * system_admin          => სისტემური ადმინისტრატორი
     * hr                    => HR
     * finance               => ფინანსისტი
     * project_manager       => პროექტის მენეჯერი
     * foreman               => ბრიგადირი
     * warehouse_keeper      => საწყობის პასუხისმგებელი
     * employee              => თანამშრომელი
     * procurement_manager   => შესყიდვების მენეჯერი
     * qa_safety             => ხარისხის/უსაფრთხოების სპეციალისტი
     * client_subcontractor  => კლიენტი/ქვეკონტრაქტორი
     *
     * @var list<string>
     */
    public const ROLES = [
        'owner',
        'system_admin',
        'hr',
        'finance',
        'project_manager',
        'foreman',
        'warehouse_keeper',
        'employee',
        'procurement_manager',
        'qa_safety',
        'client_subcontractor',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ROLES as $role) {
            Role::query()->firstOrCreate(
                ['name' => $role, 'guard_name' => 'web', 'organization_id' => null],
            );
        }
    }
}
