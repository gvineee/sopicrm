<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Access domain — docs/data-model.md "sessions": "Laravel's standard session
 * table ... used for the database session driver." Local/staging/prod
 * actually run SESSION_DRIVER=redis (DEC-025), so this table is not on the
 * live session read/write path today — it is created anyway because the
 * data model explicitly lists it as a P0 entity, and it's the schema Laravel
 * itself expects the moment SESSION_DRIVER=database is ever selected (e.g. a
 * future deployment without Redis available). No `organization_id`
 * (data-model.md explicit: session content already scopes via `user_id`);
 * login audit correlates to `AuditEvent` instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Defensive existence check: this exact dev Postgres database
        // already had an untracked `sessions` table (same column shape) from
        // before this migration file existed — likely drift from an earlier,
        // interrupted local session. Guarding here keeps this migration
        // idempotent/safe on that pre-existing database while still creating
        // the table correctly on any fresh install/CI database.
        if (Schema::hasTable('sessions')) {
            return;
        }

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
