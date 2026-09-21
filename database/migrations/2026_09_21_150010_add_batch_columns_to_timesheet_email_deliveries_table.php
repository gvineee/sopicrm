<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TIMESHEET-EMAIL-02: additive-only extension of TIMESHEET-EMAIL-01's
 * existing `timesheet_email_deliveries` table. `batch_id` is nullable — a
 * single-timesheet send (TimesheetController::emailSend(), unchanged) never
 * sets it; a batch-created delivery always does. `cancelled_at` lets
 * "cancel the remaining pending part of a batch" apply to a delivery still
 * sitting at `status = 'queued'` without inventing a 4th status value (an
 * enum alter is avoidable): the effective state a caller should treat as
 * cancelled is `status = 'queued' AND cancelled_at IS NOT NULL`.
 * App\Jobs\Timesheets\SendTimesheetEmailJob's idempotency check is extended
 * (one extra clause) to also treat a cancelled delivery as a no-op, exactly
 * like an already-resolved one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheet_email_deliveries', function (Blueprint $table) {
            $table->foreignUuid('batch_id')->nullable()->after('id')
                ->constrained('timesheet_email_batches')->nullOnDelete();
            $table->timestampTz('cancelled_at')->nullable();

            $table->index(['organization_id', 'batch_id']);
        });
    }

    public function down(): void
    {
        Schema::table('timesheet_email_deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('batch_id');
            $table->dropColumn('cancelled_at');
        });
    }
};
