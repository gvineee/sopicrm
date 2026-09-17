<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Access domain — docs/data-model.md "organizations".
 *
 * This IS the tenant root: it has no `organization_id` column of its own and
 * is intentionally excluded from Postgres RLS (see
 * 2026_09_16_090110_add_row_level_security_to_business_tables.php) — every
 * other business table's RLS policy is defined in terms of this table's id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->char('default_currency', 3)->default('GEL');
            $table->string('default_timezone')->default('Asia/Tbilisi');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
