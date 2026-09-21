<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TIMESHEET-EMAIL-02: groups a set of TimesheetEmailDelivery rows created
 * from one "send selected timesheets" action. `mode = per_employee` creates
 * one delivery per employee (their own selected timesheet(s), one email
 * each — see App\Domain\Timesheets\Actions\CreateTimesheetEmailBatchAction);
 * `mode = bundled` creates exactly ONE delivery (to `bundled_recipient_*`)
 * whose actual attachments are tracked via the new
 * `timesheet_email_delivery_items` table, since one delivery row's own
 * `timesheet_id`/`attachment_id` columns (unchanged from TIMESHEET-EMAIL-01)
 * can only reference a single timesheet.
 *
 * `skipped_details` (jsonb) records every selected timesheet that could NOT
 * be turned into a delivery — permission mismatch, cross-organization id,
 * no resolvable recipient email, etc. — with an explicit reason, per the
 * ticket's own "ჩუმი გამოტოვების გარეშე" (no silent skipping) requirement.
 * A dedicated table was judged unnecessary at this table's realistic scale
 * (tens of entries per batch): the full list is always read at once
 * alongside its own batch row, never queried/filtered independently.
 *
 * `total_count`/`status` are the only denormalized rollup kept here —
 * per-status counts (queued/sent/failed) are computed live from
 * `timesheet_email_deliveries` at read time instead of duplicated here,
 * to avoid a second place those counters could drift out of sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timesheet_email_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->enum('mode', ['per_employee', 'bundled']);
            $table->enum('status', ['pending', 'processing', 'completed', 'cancelled'])->default('pending');
            $table->foreignUuid('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('bundled_recipient_email')->nullable();
            $table->foreignUuid('bundled_recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('total_count')->default(0);
            $table->jsonb('skipped_details')->nullable();
            $table->timestampTz('created_at');

            $table->index(['organization_id', 'status']);
        });

        $this->enableRowLevelSecurity();
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_email_batches');
    }

    private function enableRowLevelSecurity(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('alter table timesheet_email_batches enable row level security');
        DB::statement('alter table timesheet_email_batches force row level security');
        DB::statement(<<<'SQL'
            create policy timesheet_email_batches_tenant_isolation on timesheet_email_batches
            using (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
            with check (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
        SQL);
    }
};
