<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration — Tasks, Comments & Attachments module (spec section 10
 * task workflow part), per docs/architecture.md §3.5 ("a module-building
 * agent that discovers a genuinely missing column/table/index does not edit
 * an existing migration file — it creates a NEW, additive migration"). The
 * P0+P1 schema pass's `tasks`/`attachments` tables (docs/data-model.md) did
 * not model three things this module's spec text needs; each is a routine
 * technical decision, logged in docs/decisions.md:
 *
 * 1. `tasks.requires_photo_evidence` / `min_required_photos`: spec section
 *    10's "მინიმალური მტკიცებულება დავალების ტიპის მიხედვით" (minimum
 *    evidence per task type) has no modeled "task type" entity anywhere in
 *    docs/data-model.md — a per-task, manager-configured evidence
 *    requirement is the smallest honest implementation that doesn't invent
 *    an unspecified taxonomy.
 * 2. `tasks.progress_weight`: spec section 10's "წინასწარ განსაზღვრული
 *    წონები" (predefined weights) for project-progress calculation needs
 *    somewhere to live; nullable so a task with no assigned weight is
 *    simply excluded from the weighted rollup rather than defaulting to a
 *    guessed number (see App\Domain\Tasks\Services\ProjectProgressService).
 * 3. `task_status_events`: spec section 10's "სრული ისტორია დარჩეს
 *    შენარჩუნებული" (full history retained) for the task state machine —
 *    `tasks.status` alone is a single mutable column with no history trail.
 * 4. `attachments.gps_*`: spec section 10's "სურვილისამებრ GPS გამჭვირვალე
 *    თანხმობით, არასდროს ჩუმად თვალთვალი" (optional GPS with transparent
 *    consent, never silent tracking) — the P0+P1 `attachments` table has no
 *    location columns at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->boolean('requires_photo_evidence')->default(true)->after('self_close_allowed');
            $table->unsignedTinyInteger('min_required_photos')->default(1)->after('requires_photo_evidence');
            $table->decimal('progress_weight', 8, 4)->nullable()->after('accepted_quantity');
        });

        Schema::table('attachments', function (Blueprint $table) {
            $table->decimal('gps_latitude', 10, 7)->nullable()->after('taken_at_client_claimed');
            $table->decimal('gps_longitude', 10, 7)->nullable()->after('gps_latitude');
            $table->boolean('gps_consent_given')->default(false)->after('gps_longitude');
        });

        Schema::create('task_status_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->index(['organization_id', 'task_id', 'occurred_at']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('alter table task_status_events enable row level security');
            DB::statement('alter table task_status_events force row level security');

            DB::statement(<<<'SQL'
                create policy task_status_events_tenant_isolation on task_status_events
                using (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
                with check (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('drop policy if exists task_status_events_tenant_isolation on task_status_events');
        }

        Schema::dropIfExists('task_status_events');

        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn(['gps_latitude', 'gps_longitude', 'gps_consent_given']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['requires_photo_evidence', 'min_required_photos', 'progress_weight']);
        });
    }
};
