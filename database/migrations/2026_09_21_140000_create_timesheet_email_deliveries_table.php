<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TIMESHEET-EMAIL-01: send-history/audit table for emailing a single
 * Timesheet snapshot. `attachment_id` points at the immutable PDF snapshot
 * (an `attachments` row owned by the Timesheet, created on first send at a
 * given `timesheet_version_at_send` and reused for every later send of that
 * SAME version — see App\Domain\Timesheets\Actions\SendTimesheetEmailAction).
 *
 * `status` is deliberately only `queued`/`sent`/`failed` — never
 * `delivered`. Per the overnight goal's explicit constraint, this app only
 * ever knows whether the mail transport ACCEPTED the message for sending
 * (SMTP-level), never whether it was actually delivered to/opened by the
 * recipient's mailbox; claiming `delivered` would be a fabricated capability
 * claim.
 *
 * `recipient_user_id` is nullable: per docs/architecture.md's explicit
 * "Employee and login account are separate concepts" rule (an Employee may
 * have no `user_id` at all), a timesheet's natural recipient — the employee
 * it belongs to — may have no User account to link this to; the row still
 * needs a real `recipient_email` to actually send to regardless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timesheet_email_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('timesheet_id')->constrained('timesheets')->cascadeOnDelete();
            $table->unsignedInteger('timesheet_version_at_send');
            $table->foreignUuid('attachment_id')->constrained('attachments')->restrictOnDelete();
            $table->string('recipient_email');
            $table->foreignUuid('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject');
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->text('failed_reason')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->foreignUuid('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->index(['organization_id', 'timesheet_id']);
            $table->index(['organization_id', 'status']);
        });

        $this->enableRowLevelSecurity();
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_email_deliveries');
    }

    private function enableRowLevelSecurity(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('alter table timesheet_email_deliveries enable row level security');
        DB::statement('alter table timesheet_email_deliveries force row level security');
        DB::statement(<<<'SQL'
            create policy timesheet_email_deliveries_tenant_isolation on timesheet_email_deliveries
            using (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
            with check (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
        SQL);
    }
};
