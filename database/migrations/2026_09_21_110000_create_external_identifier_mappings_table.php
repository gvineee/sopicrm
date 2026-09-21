<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BIO-02: durable triage queue for an external (BioStar) card/user/device
 * identifier this CRM does not yet recognize. `IngestRawAccessEventAction`
 * already refuses to auto-create an Employee for an unmatched card — this
 * table is what makes that unmatched reference DISCOVERABLE (an admin triage
 * page) instead of only visible by manually scanning `raw_access_events`.
 *
 * `source_instance_key` defaults to a non-null sentinel ('default') rather
 * than being nullable: Postgres treats each NULL as distinct for uniqueness
 * purposes, so a nullable key would silently defeat the very collision
 * protection this table exists for once a second real BioStar source is
 * connected. Today every organization has exactly one implicit source, so
 * everything keys off 'default' — a genuine second source is a documented,
 * deferred next slice (see docs/claude-overnight-progress.md BIO-02 entry),
 * not guessed at here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_identifier_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('source_system')->default('biostar');
            $table->string('source_instance_key')->default('default');
            $table->enum('external_type', ['card', 'device', 'user']);
            // "{card_type}:{raw_bytes_hex}" for cards (matches
            // RawAccessEvent.unmatched_credential_ref exactly), the vendor's
            // own id string for device/user — never a CRM-side UUID.
            $table->string('external_identifier');
            $table->enum('status', ['pending', 'confirmed', 'ignored'])->default('pending');
            // Polymorphic-by-convention (not a real morphTo relation): only
            // App\Domain\Devices\Models\Credential is wired as a resolvable
            // target today (the 'card' external_type — see
            // ConfirmExternalIdentifierMappingAction). 'device'/'user'
            // mappings can be recorded but have no confirm action yet.
            $table->string('target_type')->nullable();
            $table->uuid('target_id')->nullable();
            $table->timestampTz('first_seen_at');
            $table->foreignUuid('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->unique(
                ['organization_id', 'source_system', 'source_instance_key', 'external_type', 'external_identifier'],
                'external_identifier_mappings_unique_ref'
            );
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'external_type', 'status']);
        });

        $this->enableRowLevelSecurity();
    }

    public function down(): void
    {
        Schema::dropIfExists('external_identifier_mappings');
    }

    private function enableRowLevelSecurity(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('alter table external_identifier_mappings enable row level security');
        DB::statement('alter table external_identifier_mappings force row level security');
        DB::statement(<<<'SQL'
            create policy external_identifier_mappings_tenant_isolation on external_identifier_mappings
            using (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
            with check (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
        SQL);
    }
};
