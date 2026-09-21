<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TIMESHEET-EMAIL-02: one row per timesheet actually attached to a
 * `timesheet_email_deliveries` row created in `bundled` mode (one delivery
 * = one email to one recipient, carrying N timesheets' snapshots as
 * separate attachments). A `per_employee`-mode delivery never has any rows
 * here — it is a single-timesheet send exactly like TIMESHEET-EMAIL-01's
 * own, still fully described by the delivery row's own
 * `timesheet_id`/`attachment_id` columns.
 *
 * `timesheet_version_at_send`/`attachment_id` are captured per item at
 * batch-creation time (not read live from the Timesheet), so a later edit
 * to any ONE of the bundled timesheets can never change what a bundled
 * email actually attaches after the fact — mirrors
 * TimesheetEmailDelivery.timesheet_version_at_send's own immutability
 * guarantee, just per-item instead of per-delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timesheet_email_delivery_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('delivery_id')->constrained('timesheet_email_deliveries')->cascadeOnDelete();
            $table->foreignUuid('timesheet_id')->constrained('timesheets')->restrictOnDelete();
            $table->unsignedInteger('timesheet_version_at_send');
            $table->foreignUuid('attachment_id')->constrained('attachments')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->unique(['delivery_id', 'timesheet_id']);
        });

        $this->enableRowLevelSecurity();
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_email_delivery_items');
    }

    private function enableRowLevelSecurity(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('alter table timesheet_email_delivery_items enable row level security');
        DB::statement('alter table timesheet_email_delivery_items force row level security');
        DB::statement(<<<'SQL'
            create policy timesheet_email_delivery_items_tenant_isolation on timesheet_email_delivery_items
            using (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
            with check (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
        SQL);
    }
};
