<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance domain, core tables — docs/data-model.md "Domain: Attendance"
 * (spec section 7). `timesheets`/`timesheet_lines` are deferred to a later
 * migration since `timesheets.pay_period_id` FKs into `pay_periods`
 * (Payroll domain), which does not exist until
 * 2026_09_16_090170_create_payroll_domain_tables.php runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Genuinely append-only (data-model.md explicit: "No updated_at/
        // version mutation path"). Deliberately has neither `updated_at` nor
        // `version` — there is no code path that ever updates a row here;
        // corrections happen only at the AttendanceAdjustment layer.
        Schema::create('raw_access_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('device_id')->constrained();
            $table->unsignedBigInteger('native_event_id');
            $table->unsignedBigInteger('stream_epoch');
            $table->timestampTz('raw_device_time');
            $table->timestampTz('normalized_event_time_utc');
            $table->timestampTz('received_at');
            $table->foreignUuid('credential_id')->nullable()->constrained()->nullOnDelete();
            $table->string('unmatched_credential_ref')->nullable();
            $table->string('event_code');
            $table->string('event_subcode')->nullable();
            $table->enum('reader_direction_snapshot', ['in', 'out', 'unspecified'])->default('unspecified');
            $table->json('payload');
            $table->string('payload_hash')->nullable();
            $table->string('ingestion_source');
            $table->timestampTz('created_at')->useCurrent();

            // Dedup hard rule (data-model.md): a device reset/native-ID
            // rollover bumps stream_epoch, so old/new events sharing a
            // native_event_id under different epochs are distinct rows,
            // never silently overwritten. payload_hash is an auxiliary
            // secondary check only, never the dedup key itself.
            $table->unique(
                ['organization_id', 'device_id', 'native_event_id', 'stream_epoch'],
                'raw_access_events_dedup_unique'
            );
            $table->index(['organization_id', 'credential_id']);
            $table->index(['organization_id', 'normalized_event_time_utc']);
        });

        Schema::create('shift_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->time('starts_at_local');
            $table->time('ends_at_local');
            $table->boolean('crosses_midnight')->default(false);
            $table->json('scheduled_days');
            $table->json('break_policy');
            $table->unsignedInteger('allowed_late_minutes')->default(0);
            // Documented, never hardcoded (data-model.md/spec section 7-8:
            // rounding/threshold rules are configurable business policy).
            $table->json('rounding_policy');
            $table->string('requires_approval_by_role')->nullable();
            $table->softDeletes();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);
        });

        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('employee_id')->constrained();
            $table->foreignUuid('shift_template_id')->constrained();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'employee_id']);
        });

        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('employee_id')->constrained();
            $table->foreignUuid('site_id')->constrained();
            $table->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('clock_in_event_id')->nullable()->constrained('raw_access_events')->nullOnDelete();
            $table->foreignUuid('clock_out_event_id')->nullable()->constrained('raw_access_events')->nullOnDelete();
            $table->timestampTz('clock_in_at')->nullable();
            $table->timestampTz('clock_out_at')->nullable();
            // Local business date (site timezone) — night shifts attribute
            // to the shift's START date, never derived ad hoc at render time.
            $table->date('work_date');
            $table->unsignedInteger('raw_duration_minutes')->nullable();
            $table->unsignedInteger('payable_minutes')->nullable();
            $table->uuid('reconstruction_run_id')->nullable();
            $table->enum('status', ['open', 'closed', 'superseded'])->default('open');
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'employee_id', 'work_date']);
            $table->index(['organization_id', 'site_id', 'work_date']);
            $table->index(['organization_id', 'status']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            // data-model.md hard rule: overlapping sessions for the same
            // employee are blocked at the DB level. `coalesce(...,
            // 'infinity')` lets an open session (no clock-out yet) still
            // participate in the overlap check against any later session.
            DB::statement(<<<'SQL'
                alter table attendance_sessions add constraint attendance_sessions_no_overlap
                exclude using gist (
                    employee_id with =,
                    tstzrange(clock_in_at, coalesce(clock_out_at, 'infinity'::timestamptz), '[]') with &&
                ) where (status != 'superseded')
            SQL);
        }

        // Break-deduction idempotency: a given (session, shift template,
        // break window) is applied to payable_minutes at most once per
        // recompute pass (data-model.md explicit design for this table).
        Schema::create('attendance_session_break_deductions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('attendance_session_id')->constrained();
            $table->foreignUuid('shift_template_id')->constrained();
            $table->string('break_window_key');
            $table->unsignedInteger('deducted_minutes');
            $table->timestampsTz();

            $table->unique(
                ['attendance_session_id', 'shift_template_id', 'break_window_key'],
                'attendance_break_deductions_unique'
            );
        });

        Schema::create('attendance_anomalies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('employee_id')->constrained();
            $table->foreignUuid('attendance_session_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('anomaly_type', [
                'duplicate_in', 'unknown_out', 'missing_out', 'excessive_duration',
                'impossible_site_crossing', 'late_arriving_data', 'clock_drift',
                'out_of_order_events', 'data_gap',
            ]);
            $table->timestampTz('detected_at');
            $table->json('details');
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignUuid('resolved_by_user_id')->nullable()->constrained('users');
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'employee_id', 'resolved_at']);
        });

        Schema::create('attendance_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('employee_id')->constrained();
            $table->date('work_date');
            $table->foreignUuid('site_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('corrected_clock_in_at')->nullable();
            $table->timestampTz('corrected_clock_out_at')->nullable();
            $table->decimal('corrected_hours', 6, 2)->nullable();
            $table->string('reason');
            $table->foreignUuid('evidence_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->foreignUuid('requested_by_user_id')->constrained('users');
            $table->foreignUuid('approved_by_user_id')->nullable()->constrained('users');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            // Reference only — the original row is never overwritten
            // (data-model.md explicit: "ორიგინალი არ გადაიწეროს").
            $table->foreignUuid('original_session_id')->nullable()->constrained('attendance_sessions')->nullOnDelete();
            // A late-arriving raw event for an already-locked timesheet
            // period creates an adjustment flagged true here instead of
            // silently mutating locked history (spec section 7 explicit).
            $table->boolean('for_locked_period')->default(false);
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'employee_id', 'work_date']);
            $table->index(['organization_id', 'status']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                alter table attendance_adjustments add constraint attendance_adjustments_positive_duration
                check (
                    corrected_clock_in_at is null or corrected_clock_out_at is null
                    or corrected_clock_out_at > corrected_clock_in_at
                )
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_adjustments');
        Schema::dropIfExists('attendance_anomalies');
        Schema::dropIfExists('attendance_session_break_deductions');
        Schema::dropIfExists('attendance_sessions');
        Schema::dropIfExists('shift_assignments');
        Schema::dropIfExists('shift_templates');
        Schema::dropIfExists('raw_access_events');
    }
};
