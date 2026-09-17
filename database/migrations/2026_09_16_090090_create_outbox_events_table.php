<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-cutting — docs/data-model.md "outbox_events" / docs/architecture.md
 * §5 "Outbox" / DEC-014: a row here is inserted in the SAME DB transaction
 * as the business write it describes. A relay (see
 * App\Console\Commands\RelayOutboxEvents) reads unprocessed rows and
 * dispatches idempotent queue jobs — consumers check `processed_at`/their
 * own idempotency key before applying side effects, so at-least-once
 * delivery never double-applies an effect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->string('event_type');
            $table->uuidMorphs('subject');
            $table->json('payload');
            $table->timestamp('available_at');
            $table->timestamp('processed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['processed_at', 'available_at']);
            $table->index(['organization_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
