<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-cutting — docs/data-model.md "idempotency_records" / DEC-015 / spec
 * section 20: keyed by (organization_id, idempotency_key, endpoint
 * signature). Same key + same request-body hash replays the cached
 * response; same key + a different hash is a 409 Conflict — enforced by
 * App\Http\Middleware\EnsureIdempotencyKey.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('idempotency_key');
            $table->string('endpoint_signature');
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status');
            $table->json('response_body')->nullable();
            $table->timestamp('created_at');

            $table->unique(['organization_id', 'idempotency_key', 'endpoint_signature'], 'idempotency_records_unique_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
    }
};
