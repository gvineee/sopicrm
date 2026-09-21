<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MONEY-01 (docs/claude-platform-completion-2026-09-21.md, audit finding D1):
 * "იგივე მოთხოვნის retry ერთ გადახდას ტოვებს" — a client-generated request id
 * (one per form submission instance, resent unchanged on any retry of that
 * SAME submission) lets App\Domain\Payroll\Actions\RecordPaymentAction and
 * App\Domain\Contractors\Actions\RecordContractorPaymentAction detect and
 * short-circuit a duplicate before creating a second payment row, without
 * needing the header-based App\Http\Middleware\EnsureIdempotencyKey
 * mechanism (built for machine/API clients, not this app's existing
 * Inertia web forms).
 *
 * Nullable + unique-per-organization (a NULL request_id, from any caller
 * that doesn't supply one, is never treated as a duplicate of another NULL —
 * standard SQL unique-index behavior — so this is purely additive for any
 * existing row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->uuid('request_id')->nullable()->after('reference');
            $table->unique(['organization_id', 'request_id']);
        });

        Schema::table('contractor_payments', function (Blueprint $table): void {
            $table->uuid('request_id')->nullable()->after('reference');
            $table->unique(['organization_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'request_id']);
            $table->dropColumn('request_id');
        });

        Schema::table('contractor_payments', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'request_id']);
            $table->dropColumn('request_id');
        });
    }
};
