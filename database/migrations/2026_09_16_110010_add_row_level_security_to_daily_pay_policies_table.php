<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Payroll module — additive migration, same RLS policy shape as
 * 2026_09_16_090230_add_row_level_security_to_p1_business_tables.php
 * (docs/architecture.md §4, DEC-016). `daily_pay_policies` (this module's
 * own new table — see 2026_09_16_110000_create_daily_pay_policies_table.php)
 * carries real per-tenant `organization_id` data and must not be left
 * uncovered by RLS the way `attachments`/`notifications` briefly were
 * (docs/decisions.md DEC-059's exact gap, avoided here from the start).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('alter table daily_pay_policies enable row level security');
        DB::statement('alter table daily_pay_policies force row level security');

        DB::statement(<<<'SQL'
            create policy daily_pay_policies_tenant_isolation on daily_pay_policies
            using (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
            with check (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('drop policy if exists daily_pay_policies_tenant_isolation on daily_pay_policies');
        DB::statement('alter table daily_pay_policies no force row level security');
        DB::statement('alter table daily_pay_policies disable row level security');
    }
};
