<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance domain — `timesheets`/`timesheet_lines` (docs/data-model.md,
 * spec section 7). Split from
 * 2026_09_16_090150_create_attendance_core_tables.php because
 * `timesheets.pay_period_id` FKs into `pay_periods`, which only exists after
 * the Payroll domain migration runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timesheets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('employee_id')->constrained();
            $table->foreignUuid('pay_period_id')->constrained();
            $table->enum('status', ['draft', 'submitted', 'approved', 'locked', 'rejected'])->default('draft');
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignUuid('submitted_by_user_id')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->foreignUuid('approved_by_user_id')->nullable()->constrained('users');
            $table->string('rejected_reason')->nullable();
            $table->timestampTz('locked_at')->nullable();
            // Which attendance_sessions rows + their `version`, and which
            // calculation-policy version, were used at approval time (spec
            // section 7 explicit requirement).
            $table->json('source_sessions_version_snapshot')->nullable();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            // Routine decision (docs/decisions.md): one timesheet per
            // employee per pay period.
            $table->unique(['organization_id', 'employee_id', 'pay_period_id']);
        });

        Schema::create('timesheet_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('timesheet_id')->constrained();
            $table->date('work_date');
            $table->foreignUuid('project_id')->constrained();
            $table->foreignUuid('attendance_session_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('payable_minutes');
            $table->enum('rate_type', ['hourly', 'daily']);
            $table->foreignUuid('rate_snapshot_id')->constrained('rate_histories');
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'timesheet_id']);
            $table->index(['organization_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_lines');
        Schema::dropIfExists('timesheets');
    }
};
