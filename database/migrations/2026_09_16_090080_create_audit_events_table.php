<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-cutting — docs/data-model.md "audit_events" / docs/architecture.md
 * §5 "Audit": append-only (no soft-delete, no update path — see
 * app/Policies/AuditEventPolicy.php, which denies update/delete to every
 * ordinary role). Captures actor, action, polymorphic target, timestamp,
 * reason, request id, and a before/after diff with sensitive fields masked
 * at write time by App\Domain\Shared\Services\AuditLogger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_label')->nullable();
            $table->string('action');
            $table->uuidMorphs('target');
            $table->string('reason')->nullable();
            $table->uuid('request_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at');

            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
