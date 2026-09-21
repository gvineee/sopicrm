<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['zones', 'doors'] as $table) {
            DB::statement("drop policy if exists {$table}_organization_isolation on {$table}");
            DB::statement(
                "create policy {$table}_organization_isolation on {$table} ".
                "using (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid) ".
                "with check (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['zones', 'doors'] as $table) {
            DB::statement("drop policy if exists {$table}_organization_isolation on {$table}");
        }
    }
};
