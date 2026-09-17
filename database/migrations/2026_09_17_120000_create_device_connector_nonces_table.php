<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_connector_nonces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignId('personal_access_token_id')->constrained('personal_access_tokens')->cascadeOnDelete();
            $table->string('nonce', 128);
            $table->timestampTz('request_timestamp');
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['organization_id', 'personal_access_token_id', 'nonce'],
                'device_connector_nonces_unique'
            );
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_connector_nonces');
    }
};
