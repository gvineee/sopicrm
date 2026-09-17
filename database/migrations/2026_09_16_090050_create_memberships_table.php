<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Access domain — docs/data-model.md "memberships": which organizations a
 * user account can access at all (ahead of the P4 multi-org UX). Business
 * table: RLS-enabled (see 090110).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('user_id')->constrained();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->unsignedInteger('version')->default(1);

            $table->unique(['user_id', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
