<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NOTIFY-01: read-only Telegram reporting. `telegram_links` is the
 * one-time-code account-linking record — a `telegram_chat_id` alone is
 * NEVER trusted as identity by itself; every report send re-derives the
 * linked user's CURRENT real permissions at send time
 * (App\Domain\Notifications\Actions\SendTelegramReportAction), so a
 * demoted/deactivated user stops receiving privileged summaries
 * immediately, not just at next login. `telegram_chat_id` is a string, not
 * an integer — Telegram chat ids can exceed a 32-bit int range and this
 * app makes no arithmetic use of the value.
 *
 * `telegram_report_deliveries` is the send-history/retry table the
 * ticket's own acceptance line requires ("provider failure-ს აქვს
 * retry/history"). `status` is deliberately `queued`/`sent`/`failed` only
 * — same "never claim delivered, only transport-accepted" rule this
 * session's TIMESHEET-EMAIL-01 already established for email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('telegram_chat_id')->nullable();
            $table->string('link_code');
            $table->timestampTz('link_code_expires_at');
            $table->timestampTz('linked_at')->nullable();
            $table->timestamps();

            // A user may only ever have one link record; re-linking updates
            // this same row (new code/chat id) rather than accumulating
            // stale rows.
            $table->unique(['organization_id', 'user_id']);
            // Deliberately globally unique, NOT scoped per-organization: a
            // real Telegram webhook completing this handshake has no tenant
            // context yet (that's the entire point of the code — see
            // App\Domain\Notifications\Actions\CompleteTelegramLinkAction's
            // own docblock, which looks this up via withoutTenantScope()
            // then explicitly sets app.current_org_id from the row it
            // found, the same pattern App\Domain\Shared\Services\AuditLogger
            // already uses for an identical pre-tenant-context write).
            $table->unique('link_code');
        });

        Schema::create('telegram_report_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('telegram_link_id')->constrained('telegram_links')->cascadeOnDelete();
            $table->foreignUuid('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('report_type');
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->text('failed_reason')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('created_at');

            $table->index(['organization_id', 'telegram_link_id']);
            $table->index(['organization_id', 'status']);
        });

        $this->enableRowLevelSecurity();
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_report_deliveries');
        Schema::dropIfExists('telegram_links');
    }

    private function enableRowLevelSecurity(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['telegram_links', 'telegram_report_deliveries'] as $table) {
            DB::statement("alter table {$table} enable row level security");
            DB::statement("alter table {$table} force row level security");
            DB::statement(<<<SQL
                create policy {$table}_tenant_isolation on {$table}
                using (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
                with check (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
            SQL);
        }
    }
};
