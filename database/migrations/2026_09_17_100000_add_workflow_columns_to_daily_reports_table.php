<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration — Daily Journal module (spec section 11), discovered gap
 * per docs/architecture.md §3.5 ("a module-building agent that discovers a
 * genuinely missing column/table/index does not edit an existing migration
 * file — it creates a NEW, additive migration").
 *
 * The original `daily_reports` table (2026_09_16_090220_create_projects_tasks_domain_tables.php)
 * has a `status` column (draft/submitted/accepted) but no columns recording
 * WHO submitted/accepted the report and WHEN — needed for the real
 * fill -> submit -> manager-acceptance workflow spec section 11 requires
 * ("შევსება → წარდგენა → მენეჯერის მიღება") and for rendering that history
 * in the UI without joining `audit_events` for a routine display. The actual
 * approval decision record (who, target_version, reason, decided_at) is the
 * existing generic `App\Domain\Shared\Models\Approval` polymorphic table
 * (`approvable_type` = DailyReport's morph class) — these four columns are a
 * denormalized "current state" convenience only, never the source of truth
 * for the approval history itself. See docs/decisions.md for the write-up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_reports', function (Blueprint $table) {
            $table->timestampTz('submitted_at')->nullable()->after('status');
            $table->foreignUuid('submitted_by_user_id')->nullable()->after('submitted_at')->constrained('users');
            $table->timestampTz('accepted_at')->nullable()->after('submitted_by_user_id');
            $table->foreignUuid('accepted_by_user_id')->nullable()->after('accepted_at')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('daily_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by_user_id');
            $table->dropConstrainedForeignId('accepted_by_user_id');
            $table->dropColumn(['submitted_at', 'accepted_at']);
        });
    }
};
