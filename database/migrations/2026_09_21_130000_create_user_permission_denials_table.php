<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADMIN-02 (docs/claude-platform-completion-2026-09-21.md): the explicit
 * "deny override" half of user-level permission overrides. spatie/
 * laravel-permission is purely additive (role permissions OR direct grants,
 * with no native concept of revoking a permission a role would otherwise
 * grant), so a permission a role grants but that must be withheld from one
 * specific user needs its own table. `permission_name` is stored as a plain
 * string (not an FK to `permissions.id`) so the Gate::before check in
 * App\Providers\AppServiceProvider that consults this table on every
 * ability check is a single indexed lookup with no join.
 *
 * Documented precedence (see AppServiceProvider::boot()): a row here always
 * denies the named ability for that user, even over ADMIN-01's
 * platform-admin Gate::before bypass — this is deliberately the single most
 * authoritative check in the whole authorization system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_permission_denials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('permission_name');
            $table->text('reason');
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->unique(['organization_id', 'user_id', 'permission_name'], 'user_permission_denials_unique_grant');
            $table->index(['organization_id', 'user_id']);
        });

        $this->enableRowLevelSecurity();
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permission_denials');
    }

    private function enableRowLevelSecurity(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('alter table user_permission_denials enable row level security');
        DB::statement('alter table user_permission_denials force row level security');
        DB::statement(<<<'SQL'
            create policy user_permission_denials_tenant_isolation on user_permission_denials
            using (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
            with check (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
        SQL);
    }
};
