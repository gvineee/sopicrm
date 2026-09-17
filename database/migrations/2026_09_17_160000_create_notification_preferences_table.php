<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration — Notifications & PWA finalization module (spec section
 * 17: "Dedup, read/unread, deep link, მომხმარებლის preferences"),
 * per docs/architecture.md §3.5. `docs/data-model.md`'s "notifications" entry
 * covers the notification feed row itself but has no table for a user's own
 * per-type mute preferences — this is that table, a routine technical
 * decision logged in docs/decisions.md.
 *
 * Deliberately P1-scoped: only an in-app "muted_types" list. No
 * push/email-channel columns are added here (spec section 17 explicitly
 * scopes push/email to P2) — inventing unused channel-preference columns
 * now would let the UI imply a channel that doesn't actually deliver
 * anything, which the hard constraint against fabricating "looks done"
 * features forbids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('user_id')->constrained();
            // App\Domain\Notifications\Support\NotificationType constants,
            // stored as a JSON array of muted type strings — muting is
            // additive-deny (absent from this list = notifications of that
            // type are created normally).
            $table->json('muted_types')->default('[]');
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->unique(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
