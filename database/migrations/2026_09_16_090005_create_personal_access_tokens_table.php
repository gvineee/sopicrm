<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            // Token row PK stays a plain auto-increment int: Sanctum encodes
            // the bearer token itself as "{id}|{plainTextToken}" using this
            // column and never exposes it as a public business identifier, so
            // DEC-012 (UUID PKs for public identifiers) does not apply here.
            // `tokenable_id` DOES need to be uuid though, since every
            // tokenable model in this app (User, and any future machine
            // identity model) uses a uuid PK.
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
