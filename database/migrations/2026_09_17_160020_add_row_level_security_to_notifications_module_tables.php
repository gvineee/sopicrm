<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RLS coverage for this pass's own two new tables, added in the SAME phase
 * they are created (docs/data-model.md's own rule, see DEC-059) — identical
 * policy shape to 2026_09_16_090230_add_row_level_security_to_p1_business_tables.php.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = [
        'notification_preferences',
        'offline_sync_submissions',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tables as $table) {
            DB::statement("alter table {$table} enable row level security");
            DB::statement("alter table {$table} force row level security");

            DB::statement(<<<SQL
                create policy {$table}_tenant_isolation on {$table}
                using (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
                with check (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tables as $table) {
            DB::statement("drop policy if exists {$table}_tenant_isolation on {$table}");
            DB::statement("alter table {$table} no force row level security");
            DB::statement("alter table {$table} disable row level security");
        }
    }
};
