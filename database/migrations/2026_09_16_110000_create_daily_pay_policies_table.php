<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll module — additive migration (docs/architecture.md §3.5: a module
 * agent that finds a genuinely missing table creates a NEW migration file,
 * never edits an existing one). Documented in docs/decisions.md.
 *
 * Spec section 8 hard rule: "Daily policy არჩევს სრული/ნახევარი დღის
 * ზღვარს, მინიმალურ დასწრებასა და არასრული დღის ქცევას; ზღვარი არ ჩაიკეროს
 * კოდში" — the full/half-day threshold, minimum-attendance rule, and
 * incomplete-day behavior must be a configurable, confirmed record, never a
 * code constant. docs/data-model.md's own "Open business-policy questions"
 * #4 flags this exact gap and forbids inventing real threshold values — so
 * this table ships with NO seeded default row: until an accountant/finance
 * user explicitly configures and confirms one (`is_confirmed=true`), daily-
 * basis payroll calculation blocks with a clear anomaly rather than
 * guessing a threshold (mirrors the existing rate_histories "block accrual
 * when no rate resolves" pattern documented on that model).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_pay_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();

            // Minutes of same-day approved payable attendance (summed
            // across all sites/projects for that employee+work_date)
            // required to earn a full (1.0) day unit.
            $table->unsignedInteger('full_day_threshold_minutes');
            // Minutes required to earn a half (0.5) day unit when below the
            // full-day threshold. Null = the policy does not offer a half
            // day at all (incomplete_day_behavior governs everything below
            // the full threshold instead).
            $table->unsignedInteger('half_day_threshold_minutes')->nullable();
            // Below this many minutes, the day earns nothing and is
            // surfaced for manual review rather than silently paid or
            // silently dropped.
            $table->unsignedInteger('minimum_attendance_minutes');
            // What happens strictly between minimum_attendance_minutes and
            // the half/full thresholds:
            //   block               -> no day unit; requires manual review/adjustment
            //   pay_half_day        -> treat as a half day unit
            //   pay_prorated_hourly -> pay the actual minutes at the hourly
            //                          equivalent of the daily rate instead
            //                          of a fixed day-unit fraction
            $table->enum('incomplete_day_behavior', [
                'block', 'pay_half_day', 'pay_prorated_hourly',
            ])->default('block');
            // Spec section 8 hard rule: default max is 1.0 day unit per
            // employee per work date across all sites/projects unless a
            // separately-approved exception exists (pay_run_lines.exceeds_daily_cap
            // + daily_cap_exception_approval_id). Configurable per org, not
            // hardcoded as a bare "1" in the calculation Action.
            $table->decimal('max_day_units_per_work_date', 3, 2)->default(1.00);
            // Spec section 8 / open question #4: statutory-adjacent
            // thresholds must be accountant-confirmed before production use.
            // While false, the calculation Action refuses to compute
            // daily-basis pay runs under this policy (fails closed, not a
            // silent default).
            $table->boolean('is_confirmed')->default(false);
            $table->foreignUuid('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            // One active policy row per organization (spec 8: policy is
            // config, single source of truth per tenant; PayRun.
            // policy_version_snapshot records which values were actually
            // used at calculation time, so this row CAN change later
            // without silently altering an already-calculated run).
            $table->unique('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_pay_policies');
    }
};
