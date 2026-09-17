<?php

namespace Database\Seeders\Modules;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Daily Journal module's own permissions (docs/architecture.md §3.3):
 * naming convention `dailyjournal.<resource>.<action>`. Creates ONLY
 * Permission records and role-permission attachments for roles
 * RbacBaseSeeder already created — never a Role record itself.
 *
 * Grants implement spec section 3's role table for this module's data:
 * - `ბრიგადირი` (foreman) fills/submits the daily report for its own
 *   brigade's project (spec 11: "ბრიგადირი | ... დღის ანგარიში ...").
 * - `პროექტის მენეჯერი` (project_manager) fills/submits/accepts/returns for
 *   its own projects (spec 3: "თავისი პროექტები ... სამუშაოს მიღება").
 * - `მფლობელი` (owner) has full company-wide access, including the narrow,
 *   separately-permissioned, audited self-approval exception (spec 3: "მცირე
 *   კომპანიის გამონაკლისი მხოლოდ მფლობელის ცალკე უფლებით და აუდიტით") via
 *   `dailyjournal.reports.accept-own`, granted to `owner` only.
 * - No other role gets a grant here: HR/finance/warehouse/procurement/
 *   qa_safety/client/employee/system_admin are not named against this
 *   module's data in spec section 3, so they get no standing access (a
 *   future module extending the journal to those roles adds its own grant
 *   row here, not a change to this docblock's reasoning).
 */
class DailyJournalPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'dailyjournal.reports.view',
            'dailyjournal.reports.create',
            'dailyjournal.reports.update',
            'dailyjournal.reports.submit',
            'dailyjournal.reports.accept',
            'dailyjournal.reports.return',
            // Accepted-day edits create a revision (spec 11 explicit) but are
            // still a distinct, narrower grant than plain draft editing.
            'dailyjournal.reports.revise-accepted',
            // Owner-only self-approval exception (spec section 3's explicit
            // small-company carve-out) — kept as its own permission so it
            // never falls out of a broader grant by accident.
            'dailyjournal.reports.accept-own',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $grants = [
            'owner' => [
                'dailyjournal.reports.view',
                'dailyjournal.reports.create',
                'dailyjournal.reports.update',
                'dailyjournal.reports.submit',
                'dailyjournal.reports.accept',
                'dailyjournal.reports.return',
                'dailyjournal.reports.revise-accepted',
                'dailyjournal.reports.accept-own',
            ],
            'project_manager' => [
                'dailyjournal.reports.view',
                'dailyjournal.reports.create',
                'dailyjournal.reports.update',
                'dailyjournal.reports.submit',
                'dailyjournal.reports.accept',
                'dailyjournal.reports.return',
                'dailyjournal.reports.revise-accepted',
            ],
            'foreman' => [
                'dailyjournal.reports.view',
                'dailyjournal.reports.create',
                'dailyjournal.reports.update',
                'dailyjournal.reports.submit',
                // deliberately NOT accept/return/revise-accepted/accept-own —
                // a foreman fills and submits, but manager acceptance is a
                // separate role (spec 3).
            ],
        ];

        foreach ($grants as $roleName => $permissionNames) {
            /** @var Role $role */
            $role = Role::query()->where('name', $roleName)->whereNull('organization_id')->firstOrFail();
            $role->givePermissionTo($permissionNames);
        }
    }
}
