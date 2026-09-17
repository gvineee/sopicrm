<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Devices domain — docs/data-model.md "Domain: Devices" (spec section 6).
 * Suprema access-control device fleet: sites, physical readers, the
 * credential/card catalog, active card->employee assignments, access
 * schedules, and the outbound command queue that `services/device-connector`
 * drains. `raw_access_events`/`attendance_sessions` (Attendance domain) FK
 * into `devices`/`credentials` created here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('timezone')->default('Asia/Tbilisi');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unsignedInteger('version')->default(1);
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('site_id')->constrained();
            $table->string('serial_number');
            $table->string('model');
            $table->string('firmware_version')->nullable();
            $table->string('install_location')->nullable();
            $table->enum('reader_role', ['in', 'out', 'unspecified'])->default('unspecified');
            $table->string('device_timezone')->default('Asia/Tbilisi');
            $table->enum('status', ['online', 'offline', 'degraded', 'unknown'])->default('unknown');
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            // Deliberately independent of `status`: "online" never implies
            // "all credentials synced" (data-model.md explicit rule) — the UI
            // must render these two facts separately, never derive one from
            // the other.
            $table->enum('sync_status', ['in_sync', 'pending', 'error'])->default('pending');
            $table->string('connector_version')->nullable();
            $table->timestamps();
            $table->unsignedInteger('version')->default(1);

            $table->unique(['organization_id', 'serial_number']);
            $table->index(['organization_id', 'site_id']);
        });

        Schema::create('device_capabilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('device_id')->constrained();
            $table->string('capability_key');
            $table->json('capability_value');
            // When this capability snapshot was actually read live from the
            // device — never hardcoded (spec section 6: "მოწყობილობის
            // მეხსიერების ზუსტი ლიმიტი არ გამოიგონო — წაიკითხე capability").
            $table->timestamp('read_at');
            $table->timestamps();
            $table->unsignedInteger('version')->default(1);

            $table->unique(['organization_id', 'device_id', 'capability_key']);
        });

        Schema::create('credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->string('card_type');
            // Normalized decimal representation of the card number.
            $table->string('canonical_identifier');
            $table->binary('raw_bytes')->nullable();
            $table->unsignedSmallInteger('bit_length')->nullable();
            $table->boolean('leading_zeros_preserved')->default(true);
            $table->enum('status', ['unassigned', 'issued', 'lost', 'revoked', 'expired'])->default('unassigned');
            $table->timestamps();
            $table->unsignedInteger('version')->default(1);

            // Routine decision (docs/decisions.md): a physical card is
            // registered at most once per organization under a given card
            // type, so the same physical credential can't be double-entered
            // as two rows.
            $table->unique(['organization_id', 'card_type', 'canonical_identifier']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('credential_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('credential_id')->constrained();
            $table->foreignUuid('employee_id')->constrained();
            $table->timestamp('valid_from');
            $table->timestamp('valid_to')->nullable();
            $table->json('site_scope')->nullable();
            $table->enum('status', ['active', 'superseded', 'revoked', 'expired'])->default('active');
            $table->timestamps();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'employee_id']);
            $table->index(['organization_id', 'credential_id', 'valid_from']);
        });

        // data-model.md hard rule: "ბარათის აქტიური მინიჭება უნიკალურია" — at
        // most one ACTIVE assignment per credential at a time. Re-issuing a
        // card creates a new row instead of mutating this one, so history
        // (and which assignment was active at any past instant) is always
        // resolvable by a time-range query, never a mutable "current owner"
        // pointer.
        DB::statement(
            'create unique index credential_assignments_active_unique '.
            'on credential_assignments (organization_id, credential_id) '.
            "where status = 'active'"
        );

        Schema::create('access_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->string('name');
            $table->json('site_ids');
            $table->json('schedule_definition');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unsignedInteger('version')->default(1);
        });

        // Pivot preferred over a jsonb id-list on access_policies for
        // queryability (data-model.md explicit preference).
        Schema::create('access_policy_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('access_policy_id')->constrained();
            $table->foreignUuid('credential_assignment_id')->constrained();
            $table->timestamps();

            $table->unique(['access_policy_id', 'credential_assignment_id'], 'access_policy_assignments_unique');
        });

        Schema::create('device_sync_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('device_id')->constrained();
            $table->enum('command_type', [
                'add_user', 'update_user', 'revoke_credential', 'sync_access_group', 'sync_schedule',
            ]);
            $table->json('payload');
            $table->string('idempotency_key');
            // Routine decision: the "target entity" a command's monotonic
            // version is scoped to (data-model.md: "მონოტონური per
            // (device_id, target_entity)") is modeled as a polymorphic pair
            // rather than a bare string, so it can point at a
            // credential_assignment, an access_policy, etc. without a
            // separate lookup table per target kind.
            $table->string('target_entity_type')->nullable();
            $table->uuid('target_entity_id')->nullable();
            $table->unsignedInteger('command_version');
            $table->enum('status', ['pending', 'processing', 'succeeded', 'failed', 'retry', 'dead_letter'])
                ->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->unsignedInteger('version')->default(1);

            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['organization_id', 'device_id', 'status']);
            $table->index(
                ['device_id', 'target_entity_type', 'target_entity_id', 'command_version'],
                'device_sync_commands_target_version_idx'
            );
        });

        Schema::create('device_checkpoints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('device_id')->constrained();
            $table->unsignedBigInteger('stream_epoch')->default(0);
            $table->unsignedBigInteger('last_native_event_id')->default(0);
            $table->timestamp('last_confirmed_at')->nullable();
            $table->timestamps();
            $table->unsignedInteger('version')->default(1);

            $table->unique(['organization_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_checkpoints');
        Schema::dropIfExists('device_sync_commands');
        Schema::dropIfExists('access_policy_assignments');
        Schema::dropIfExists('access_policies');
        Schema::dropIfExists('credential_assignments');
        Schema::dropIfExists('credentials');
        Schema::dropIfExists('device_capabilities');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('sites');
    }
};
