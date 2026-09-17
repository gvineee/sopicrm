<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects & Tasks domain — docs/data-model.md "Domain: Projects & Tasks"
 * (spec section 10/11). Depends on `employees` (Employees domain),
 * `attachments`/`document_revisions` (cross-cutting), and the now-completed
 * `projects`/`clients` (previous migration in this pass).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('project_id')->constrained();
            // FK deferred below — self-referencing ->constrained() inside the
            // same Schema::create() fails on real Postgres (docs/decisions.md).
            $table->uuid('parent_location_id')->nullable();
            $table->string('level_type');
            $table->string('name');
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'project_id']);
        });

        Schema::table('project_locations', function (Blueprint $table) {
            $table->foreign('parent_location_id')->references('id')->on('project_locations')->nullOnDelete();
        });

        Schema::create('work_packages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('project_id')->constrained();
            $table->foreignUuid('project_location_id')->nullable()->constrained('project_locations')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'project_id']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('project_id')->constrained();
            $table->foreignUuid('project_location_id')->nullable()->constrained('project_locations')->nullOnDelete();
            $table->foreignUuid('work_package_id')->nullable()->constrained('work_packages')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            // Exactly one accountable owner (data-model.md explicit).
            $table->foreignUuid('accountable_owner_employee_id')->constrained('employees');
            $table->string('priority')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->unsignedInteger('planned_duration_minutes')->nullable();
            // Configurable unit string (m2/m3/linear meter/piece/custom),
            // never a hardcoded enum (spec: "კონფიგურირებადი სხვა
            // ერთეულები").
            $table->string('unit')->nullable();
            $table->decimal('planned_quantity', 12, 2)->nullable();
            $table->decimal('accepted_quantity', 12, 2)->default(0);
            $table->enum('status', [
                'draft', 'assigned', 'in_progress', 'blocked', 'submitted', 'completed', 'cancelled',
            ])->default('draft');
            $table->string('blocked_reason')->nullable();
            $table->foreignUuid('blocked_owner_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            // Manager can pre-enable self-close for low-risk tasks; visible
            // on the task and in audit (spec explicit).
            $table->boolean('self_close_allowed')->default(false);
            $table->foreignUuid('drawing_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->foreignUuid('drawing_revision_id')->nullable()->constrained('document_revisions')->nullOnDelete();
            $table->string('cancelled_reason')->nullable();
            $table->string('reopened_reason')->nullable();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'project_id', 'status']);
            $table->index(['organization_id', 'accountable_owner_employee_id']);
        });

        Schema::create('task_assignees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('task_id')->constrained();
            $table->foreignUuid('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('team_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampsTz();

            $table->index(['organization_id', 'task_id']);
        });

        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('task_id')->constrained();
            $table->foreignUuid('depends_on_task_id')->constrained('tasks');
            $table->timestampsTz();

            // Prevents a duplicate edge; cycle prevention for arbitrary
            // depth is enforced at the Domain Action level via a recursive
            // CTE check inside the same transaction as the insert
            // (data-model.md explicit — Postgres has no native constraint
            // for this).
            $table->unique(['task_id', 'depends_on_task_id'], 'task_dependencies_unique');
        });

        Schema::create('checklist_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('task_id')->constrained();
            $table->string('label');
            $table->boolean('is_required')->default(true);
            $table->boolean('is_checked')->default(false);
            $table->foreignUuid('checked_by_user_id')->nullable()->constrained('users');
            $table->timestampTz('checked_at')->nullable();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'task_id']);
        });

        Schema::create('task_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('task_id')->constrained();
            $table->foreignUuid('submitted_by_employee_id')->constrained('employees');
            $table->decimal('submitted_quantity', 12, 2)->nullable();
            $table->text('comment')->nullable();
            $table->json('photo_attachment_ids');
            $table->timestampTz('submitted_at');
            $table->enum('status', ['pending_review', 'accepted', 'returned'])->default('pending_review');
            $table->string('returned_reason')->nullable();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'task_id']);
        });

        Schema::create('task_acceptances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            // Unique: one acceptance per submission — idempotent, prevents
            // double-counting toward tasks.accepted_quantity
            // (data-model.md explicit hard rule).
            $table->foreignUuid('task_submission_id')->unique()->constrained('task_submissions');
            $table->foreignUuid('accepted_by_user_id')->constrained('users');
            $table->decimal('accepted_quantity', 12, 2);
            $table->timestampTz('accepted_at');
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->uuidMorphs('commentable');
            $table->foreignUuid('author_user_id')->constrained('users');
            $table->text('body');
            $table->json('mentions')->nullable();
            // FK deferred below — self-referencing ->constrained() inside the
            // same Schema::create() fails on real Postgres (docs/decisions.md).
            $table->uuid('parent_comment_id')->nullable();
            $table->timestampTz('edited_at')->nullable();
            $table->json('edit_history')->nullable();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'commentable_type', 'commentable_id']);
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->foreign('parent_comment_id')->references('id')->on('comments')->nullOnDelete();
        });

        Schema::create('daily_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('project_id')->constrained();
            $table->date('report_date');
            $table->foreignUuid('responsible_user_id')->constrained('users');
            $table->json('teams_present');
            $table->unsignedInteger('headcount_from_attendance')->nullable();
            $table->unsignedInteger('headcount_manual_override')->nullable();
            $table->string('headcount_variance_note')->nullable();
            $table->text('work_performed_note')->nullable();
            $table->json('equipment_used')->nullable();
            $table->text('materials_received_note')->nullable();
            $table->text('delays_note')->nullable();
            $table->text('quality_safety_note')->nullable();
            $table->json('photo_attachment_ids');
            $table->text('next_day_plan')->nullable();
            // Manual initially; automated weather service is optional/future
            // (spec section 11 explicit).
            $table->string('weather_manual')->nullable();
            $table->enum('status', ['draft', 'submitted', 'accepted'])->default('draft');
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->unique(['organization_id', 'project_id', 'report_date']);
        });

        // Editing an already-accepted/closed day creates a revision (append,
        // not overwrite — spec 11 explicit: "დახურული დღის რედაქტირება
        // ქმნის revision-ს").
        Schema::create('daily_report_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('daily_report_id')->constrained();
            $table->json('snapshot');
            $table->foreignUuid('revised_by_user_id')->constrained('users');
            $table->timestampTz('revised_at');
            $table->string('reason')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'daily_report_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_report_revisions');
        Schema::dropIfExists('daily_reports');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('task_acceptances');
        Schema::dropIfExists('task_submissions');
        Schema::dropIfExists('checklist_items');
        Schema::dropIfExists('task_dependencies');
        Schema::dropIfExists('task_assignees');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('work_packages');
        Schema::dropIfExists('project_locations');
    }
};
