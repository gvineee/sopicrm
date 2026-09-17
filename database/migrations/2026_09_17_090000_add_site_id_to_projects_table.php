<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration — Attendance module (spec section 7, REQ-ATT-08:
 * "Multi-site-per-day time attribution to correct project"), discovered gap
 * in the P0+P1 schema per docs/architecture.md §3.5 ("a module-building
 * agent that discovers a genuinely missing column/table/index does not edit
 * an existing migration file — it creates a NEW, additive migration").
 *
 * `docs/data-model.md` has no FK linking `projects` to `sites` anywhere: an
 * `attendance_sessions` row already knows its `site_id` (from the device
 * that recorded the clock-in) but had no deterministic way to resolve which
 * `project_id` a session at that site/date belongs to when an employee has
 * more than one active `employee_project_assignments` row on the same date.
 * A project is not free to guess/fabricate this attribution (hard
 * constraint), so it needs a real, queryable site<->project link.
 *
 * Routine technical decision (see docs/decisions.md): one project has at
 * most one primary `site_id` (nullable — a project not yet tied to a
 * physical site, or a purely commercial/office project, simply never
 * auto-attributes attendance sessions to it). This is additive and
 * non-breaking: existing `projects` rows get `site_id = null` and behave
 * exactly as before (attendance session `project_id` stays null, same as
 * today). The Projects module (owner of `projects` going forward per
 * docs/architecture.md §7) may extend this to a proper many-to-many
 * `project_sites` pivot later if a real deployment needs one project to span
 * multiple sites — this column is the minimum needed to unblock REQ-ATT-08
 * without inventing anything about how Projects itself should model sites.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignUuid('site_id')->nullable()->after('client_id')->constrained('sites')->nullOnDelete();

            $table->index(['organization_id', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
        });
    }
};
