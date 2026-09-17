<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects & Tasks domain — completes the `projects` placeholder
 * (2026_09_16_090060_create_projects_table.php, deliberately left minimal by
 * the Auth/RBAC/Tenancy pass — see that migration's own docblock) with the
 * fields docs/data-model.md actually specifies, and adds `clients`. Additive
 * per docs/architecture.md §3.5: the placeholder migration is NOT edited.
 *
 * Safe to add `manager_user_id` as NOT NULL: verified via
 * `Project::withoutGlobalScopes()->count()` against the real dev database
 * before writing this migration — zero rows exist yet (docs/decisions.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->string('name');
            $table->json('contact_info')->nullable();
            $table->softDeletes();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignUuid('client_id')->nullable()->after('code')->constrained('clients')->nullOnDelete();
            $table->foreignUuid('manager_user_id')->after('client_id')->constrained('users');
            $table->string('address')->nullable()->after('manager_user_id');
            $table->date('starts_on')->nullable()->after('address');
            $table->date('ends_on')->nullable()->after('starts_on');
            $table->enum('status', ['planning', 'active', 'on_hold', 'completed', 'cancelled'])
                ->default('planning')->after('ends_on');
            $table->decimal('budget_baseline', 14, 2)->nullable()->after('status');

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropForeign(['manager_user_id']);
            $table->dropColumn([
                'client_id', 'manager_user_id', 'address', 'starts_on',
                'ends_on', 'status', 'budget_baseline',
            ]);
        });

        Schema::dropIfExists('clients');
    }
};
