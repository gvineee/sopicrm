<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-cutting — docs/data-model.md "notifications" (spec section 17 P1
 * list). Dedup window: `dedup_key` is a REQUIRED string (never null) so the
 * `unique(organization_id, recipient_user_id, dedup_key)` constraint always
 * applies; a notification with no natural dedup key uses its own generated
 * UUID as `dedup_key` (i.e. "never dedup this one"), decided here as a
 * routine technical default per docs/architecture.md §3.4 — the Notifications
 * module agent may replace that fallback with a real time-bucketed key
 * without a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('recipient_user_id')->constrained('users');
            $table->string('type');
            $table->json('payload');
            $table->string('dedup_key');
            $table->timestamp('read_at')->nullable();
            $table->string('deep_link')->nullable();
            $table->timestamps();
            $table->unsignedInteger('version')->default(1);

            $table->unique(['organization_id', 'recipient_user_id', 'dedup_key']);
            $table->index(['organization_id', 'recipient_user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
