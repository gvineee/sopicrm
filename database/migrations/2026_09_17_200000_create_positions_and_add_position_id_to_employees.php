<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Predefined, organization-managed job positions (spec section 5 "პოზიცია")
 * replacing free-text entry so positions can be reliably filtered/reported
 * on (e.g. "everyone under this supervisor in position X"). Additive: the
 * existing free-text `employees.position` column is left untouched for
 * historical records; `position_id` is the new, structured field new/edited
 * employees are expected to use going forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unsignedInteger('version')->default(1);

            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'is_active']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignUuid('position_id')->nullable()->after('position')->constrained('positions')->nullOnDelete();
            $table->index(['organization_id', 'position_id']);
        });

        $this->enableRowLevelSecurity();
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('position_id');
        });

        Schema::dropIfExists('positions');
    }

    private function enableRowLevelSecurity(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('alter table positions enable row level security');
        DB::statement('alter table positions force row level security');
        DB::statement(<<<'SQL'
            create policy positions_tenant_isolation on positions
            using (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
            with check (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
        SQL);
    }
};
