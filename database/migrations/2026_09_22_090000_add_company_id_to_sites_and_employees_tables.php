<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT-01 (docs/claude-overnight-progress.md's own recorded next slice):
 * only `projects.company_id` existed before this — `sites`, `devices`, and
 * `employees` had no company-level scoping at all, only `organization_id`.
 * Within one organization with two companies, every Site/Device/Employee
 * was visible/assignable to both companies equally.
 *
 * `sites.company_id` and `employees.company_id` are additive and NULLABLE
 * — every existing row stays NULL (an honest "unmapped, needs a human
 * decision" state; no backfill is guessed here). Composite FK to
 * `(organization_id, id)` on `companies`, copied exactly from
 * `2026_09_17_181000_add_company_id_to_projects_table.php`'s own pattern,
 * so a company from a different organization can never be assigned even by
 * a request-tampering attempt.
 *
 * `devices` deliberately gets NO own `company_id` column: `Device` already
 * has a real `site_id` FK (`app/Domain/Devices/Models/Device.php`), so a
 * device's company is resolved transitively via its own site
 * (`Device::resolvedCompanyId()`) rather than duplicating the same fact in
 * two places that could drift out of sync. `Employee` has no natural
 * site relationship anywhere in this codebase (confirmed: no `site_id` on
 * `employees`) — an employee belongs to a company/project, not a physical
 * site, so it gets its own nullable column instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->uuid('company_id')->nullable()->after('organization_id');
            $table->index(['organization_id', 'company_id']);
            $table->foreign(['organization_id', 'company_id'], 'sites_company_tenant_fk')
                ->references(['organization_id', 'id'])->on('companies')
                ->nullOnDelete();
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->uuid('company_id')->nullable()->after('organization_id');
            $table->index(['organization_id', 'company_id']);
            $table->foreign(['organization_id', 'company_id'], 'employees_company_tenant_fk')
                ->references(['organization_id', 'id'])->on('companies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropForeign('sites_company_tenant_fk');
            $table->dropIndex(['organization_id', 'company_id']);
            $table->dropColumn('company_id');
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropForeign('employees_company_tenant_fk');
            $table->dropIndex(['organization_id', 'company_id']);
            $table->dropColumn('company_id');
        });
    }
};
