<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Access domain — docs/data-model.md "project_memberships": this table is
 * the concrete implementation of spec section 3's "პროექტის წევრობა და
 * როლის უფლებები ერთად განსაზღვრავს წვდომას" (project membership + role
 * jointly decide access) — every project-scoped Policy checks this table
 * alongside the user's spatie role/permission, never the permission alone.
 *
 * `removed_at` is a soft revoke that keeps history (so past assignments
 * remain auditable) rather than a hard delete; the partial unique index
 * below allows a user to be re-added to the same project after a prior
 * removal without a unique-constraint clash on the historical row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('project_id')->constrained();
            $table->foreignUuid('user_id')->constrained();
            $table->string('role_context')->nullable();
            $table->foreignUuid('added_by_user_id')->nullable()->constrained('users');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'project_id', 'user_id']);
        });

        // Partial unique index: unique(organization_id, user_id, project_id)
        // WHERE removed_at IS NULL — supported by both Postgres (used in
        // dev/staging/production) and SQLite (used by the fast Pest suite,
        // per docs/architecture.md §3.5/§3.6), so no driver branch needed.
        DB::statement(
            'create unique index project_memberships_active_unique '.
            'on project_memberships (organization_id, user_id, project_id) '.
            'where removed_at is null'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('project_memberships');
    }
};
