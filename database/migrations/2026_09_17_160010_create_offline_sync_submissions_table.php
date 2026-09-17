<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration — Notifications & PWA finalization module (spec section
 * 17): "დაბრუნებულ ქსელზე idempotent replay; parent task ცვლილებისას version
 * conflict. თუ უფლება გაუქმდა ან დავალება დაიხურა, offline submission არ
 * მიიღება ჩუმად — გადადის გასარჩევ მდგომარეობაში."
 *
 * `docs/data-model.md` has no table for this — it is genuinely missing
 * infrastructure the offline-sync flow needs, added here as a routine
 * technical decision (logged in docs/decisions.md): every offline
 * comment/photo/task-close-out replay writes exactly one row here recording
 * what was attempted and what actually happened, so:
 *  - a "for_review" row is a real, durable, queryable triage item (never a
 *    silently-dropped or silently-accepted submission) that a manager/
 *    foreman can later approve (which then creates the real
 *    Comment/TaskSubmission/Attachment) or reject;
 *  - a "conflict" row records that the client's parent-task version was
 *    stale, without ever mutating the task;
 *  - an "applied" row is the audit trail for a submission that went through
 *    immediately, linking to whatever real row it produced.
 *
 * Idempotent replay itself is NOT reinvented here — it is handled by the
 * already-existing `idempotency` middleware / `idempotency_records` table
 * (DEC-015), keyed by the offline queue item's own `idempotencyKey` sent as
 * the `Idempotency-Key` header. This table is the durable OUTCOME record,
 * not the replay-dedup mechanism.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_sync_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('user_id')->constrained();
            $table->foreignUuid('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->enum('kind', ['comment', 'photo', 'task_submission']);
            $table->foreignUuid('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            // The offline queue item's own local (client-generated) id —
            // purely for support/debugging traceability, never trusted for
            // authorization or idempotency (the Idempotency-Key header is).
            $table->string('client_item_id')->nullable();
            $table->enum('status', ['applied', 'for_review', 'rejected', 'conflict']);
            $table->json('payload');
            $table->string('reason')->nullable();
            $table->foreignUuid('resulting_comment_id')->nullable()->constrained('comments')->nullOnDelete();
            $table->foreignUuid('resulting_task_submission_id')->nullable()->constrained('task_submissions')->nullOnDelete();
            $table->foreignUuid('resulting_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->timestampTz('client_created_at')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignUuid('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_notes')->nullable();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_sync_submissions');
    }
};
