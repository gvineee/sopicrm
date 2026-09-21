<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Introduces Company as the business boundary below the Organization tenant.
 *
 * This migration is deliberately additive. Existing organizations receive a
 * single default company and existing users become members of that company,
 * preserving the previous single-company behaviour while enabling a gradual
 * move to Organization -> Company -> Site/Project ownership.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('code')->nullable();
            $table->char('default_currency', 3)->default('GEL');
            $table->string('default_timezone')->default('Asia/Tbilisi');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->unique(['organization_id', 'code']);
            $table->unique(['organization_id', 'id'], 'companies_organization_id_id_unique');
            $table->index(['organization_id', 'is_active']);
        });

        // Composite user/company FKs below prove at the database layer that
        // both records belong to the same tenant, not merely that both UUIDs
        // happen to exist independently.
        Schema::table('users', function (Blueprint $table) {
            $table->unique(['organization_id', 'id'], 'users_organization_id_id_unique');
            $table->uuid('current_company_id')->nullable()->after('current_organization_id');
            $table->index(['organization_id', 'current_company_id']);
            $table->foreign(['organization_id', 'current_company_id'], 'users_current_company_tenant_fk')
                ->references(['organization_id', 'id'])
                ->on('companies')
                ->nullOnDelete();
        });

        Schema::create('company_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('company_id');
            $table->uuid('user_id');
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign(['organization_id', 'company_id'], 'company_memberships_company_tenant_fk')
                ->references(['organization_id', 'id'])
                ->on('companies')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'user_id'], 'company_memberships_user_tenant_fk')
                ->references(['organization_id', 'id'])
                ->on('users')
                ->cascadeOnDelete();

            $table->unique(['organization_id', 'company_id', 'user_id'], 'company_memberships_unique');
            $table->index(['organization_id', 'user_id']);
        });

        DB::statement(
            'create unique index company_memberships_primary_unique '.
            'on company_memberships (organization_id, user_id) '.
            'where is_primary = true'
        );

        $this->backfillExistingOrganizations();
        $this->enableRowLevelSecurity();
    }

    public function down(): void
    {
        Schema::dropIfExists('company_memberships');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('users_current_company_tenant_fk');
            $table->dropIndex(['organization_id', 'current_company_id']);
            $table->dropColumn('current_company_id');
            $table->dropUnique('users_organization_id_id_unique');
        });

        Schema::dropIfExists('companies');
    }

    private function backfillExistingOrganizations(): void
    {
        $now = now();

        DB::table('organizations')
            ->orderBy('id')
            ->get(['id', 'name', 'legal_name', 'default_currency', 'default_timezone', 'is_active'])
            ->each(function (object $organization) use ($now): void {
                $companyId = (string) Str::uuid7();

                DB::table('companies')->insert([
                    'id' => $companyId,
                    'organization_id' => $organization->id,
                    'name' => $organization->name,
                    'legal_name' => $organization->legal_name,
                    'code' => 'DEFAULT',
                    'default_currency' => $organization->default_currency,
                    'default_timezone' => $organization->default_timezone,
                    'is_active' => $organization->is_active,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'version' => 1,
                ]);

                DB::table('users')
                    ->where('organization_id', $organization->id)
                    ->whereNull('current_company_id')
                    ->update(['current_company_id' => $companyId]);

                $memberships = DB::table('users')
                    ->where('organization_id', $organization->id)
                    ->pluck('id')
                    ->map(fn (string $userId): array => [
                        'id' => (string) Str::uuid7(),
                        'organization_id' => $organization->id,
                        'company_id' => $companyId,
                        'user_id' => $userId,
                        'is_primary' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                        'version' => 1,
                    ])
                    ->all();

                if ($memberships !== []) {
                    DB::table('company_memberships')->insert($memberships);
                }
            });
    }

    private function enableRowLevelSecurity(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['companies', 'company_memberships'] as $table) {
            DB::statement("alter table {$table} enable row level security");
            DB::statement("alter table {$table} force row level security");
            DB::statement(<<<SQL
                create policy {$table}_tenant_isolation on {$table}
                using (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
                with check (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
            SQL);
        }
    }
};
