<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll domain — docs/data-model.md "Domain: Payroll" (spec section 8).
 * All money columns are `numeric(14,2)` per docs/architecture.md §5 (DEC-011)
 * — never float. Created after `approvals` (for the day-cap exception link
 * on pay_run_lines) and before Attendance's `timesheets`/`timesheet_lines`
 * (which FK into `pay_periods` here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->unique(['organization_id', 'starts_on', 'ends_on']);
        });

        Schema::create('pay_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('pay_period_id')->constrained();
            $table->enum('status', ['draft', 'calculated', 'reviewed', 'approved', 'locked'])->default('draft');
            $table->timestampTz('calculated_at')->nullable();
            $table->foreignUuid('calculated_by_user_id')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->foreignUuid('approved_by_user_id')->nullable()->constrained('users');
            // Rounding rule + daily-policy thresholds actually used for this
            // run (spec section 8: policy is config, and the version used
            // must be recorded, never assumed from "whatever config is
            // active today" after the fact).
            $table->json('policy_version_snapshot')->nullable();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'pay_period_id']);
        });

        Schema::create('pay_run_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('pay_run_id')->constrained();
            $table->foreignUuid('employee_id')->constrained();
            $table->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('basis', ['hourly', 'daily']);
            $table->decimal('quantity', 10, 2);
            $table->foreignUuid('rate_snapshot_id')->constrained('rate_histories');
            $table->text('formula_applied');
            $table->decimal('gross_amount', 14, 2);
            $table->decimal('adjustments_amount', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2);
            // data-model.md hard rule: an employee's day-unit total across
            // all sites/projects on one work-date defaults to a max of 1.0
            // unless an explicit, separately-approved exception exists.
            // Enforced in the Domain calculation Action (aggregate check
            // across sibling lines can't be a simple column CHECK); this
            // flag + optional Approval link record that an exception was
            // granted, so the constraint's *exercise* is auditable.
            $table->boolean('exceeds_daily_cap')->default(false);
            $table->foreignUuid('daily_cap_exception_approval_id')->nullable()->constrained('approvals')->nullOnDelete();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'pay_run_id']);
            $table->index(['organization_id', 'employee_id']);
        });

        Schema::create('advances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('employee_id')->constrained();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('GEL');
            $table->timestampTz('granted_at');
            $table->foreignUuid('granted_by_user_id')->constrained('users');
            $table->string('reason');
            $table->enum('status', ['outstanding', 'fully_deducted', 'cancelled'])->default('outstanding');
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'employee_id', 'status']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('employee_id')->constrained();
            $table->foreignUuid('pay_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('paid_at');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('GEL');
            $table->string('method');
            $table->string('reference')->nullable();
            $table->foreignUuid('evidence_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            // Hard rule (data-model.md + outer task constraint): this is a
            // record-keeping ledger of payments made through an external
            // process — never a payment-execution feature, and `pending` is
            // NEVER treated as `paid` in any balance calculation.
            $table->enum('status', ['pending', 'completed', 'cancelled'])->default('pending');
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'employee_id', 'status']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('pay_run_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('advance_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('allocated_amount', 14, 2);
            $table->enum('allocation_type', ['payment_to_earnings', 'advance_deduction', 'pay_adjustment']);
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'payment_id']);
            $table->index(['organization_id', 'pay_run_line_id']);
            $table->index(['organization_id', 'advance_id']);
        });

        Schema::create('pay_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('pay_run_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('employee_id')->constrained();
            $table->enum('type', [
                'overtime', 'holiday', 'bonus', 'vacation', 'absence_deduction',
                'damaged_tool_deduction', 'penalty', 'other',
            ]);
            // Signed: positive = addition, negative = deduction.
            $table->decimal('amount', 14, 2);
            $table->string('reason');
            $table->foreignUuid('approved_by_user_id')->constrained('users');
            // Hard rule (data-model.md): deduction-type adjustments only
            // ever get created through an explicit, separately-permissioned
            // human-approved action — never an automated job.
            $table->boolean('requires_separate_permission')->default(true);
            // Corrections to an already-approved/locked period are new
            // reversal rows referencing the original, never an edit
            // (data-model.md explicit). FK deferred below — see
            // docs/decisions.md: a self-referencing ->constrained() declared
            // inside the same Schema::create() fails on real Postgres
            // (verified empirically while implementing this pass).
            $table->uuid('reverses_pay_adjustment_id')->nullable();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'employee_id']);
            $table->index(['organization_id', 'pay_run_line_id']);
        });

        Schema::table('pay_adjustments', function (Blueprint $table) {
            $table->foreign('reverses_pay_adjustment_id')->references('id')->on('pay_adjustments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_adjustments');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('advances');
        Schema::dropIfExists('pay_run_lines');
        Schema::dropIfExists('pay_runs');
        Schema::dropIfExists('pay_periods');
    }
};
