<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive device-registry enrichment. Existing serial/model/site records
 * remain valid; operational identifiers are nullable until discovered from a
 * connector, while human-facing names are backfilled from serial_number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('name')->nullable()->after('site_id');
            $table->string('vendor')->default('suprema')->after('name');
            $table->string('device_identifier')->nullable()->after('serial_number');
            $table->ipAddress('ip_address')->nullable()->after('device_identifier');
            $table->unsignedSmallInteger('port')->nullable()->after('ip_address');
            $table->string('mac_address', 17)->nullable()->after('port');
            $table->string('hardware_version')->nullable()->after('firmware_version');
            $table->string('connection_mode')->nullable()->after('hardware_version');
            $table->string('timezone')->nullable()->after('device_timezone');
            $table->timestampTz('last_seen_at')->nullable()->after('last_heartbeat_at');
            $table->timestampTz('last_sync_at')->nullable()->after('last_event_at');
            $table->json('metadata')->nullable()->after('connector_version');
            $table->boolean('enabled')->default(true)->after('metadata');

            $table->index(['organization_id', 'vendor', 'model']);
            $table->index(['organization_id', 'enabled', 'status']);
        });

        DB::table('devices')->whereNull('name')->update(['name' => DB::raw('serial_number')]);
        DB::table('devices')->whereNull('timezone')->update(['timezone' => DB::raw('device_timezone')]);

        DB::statement(
            'create unique index devices_organization_identifier_unique '.
            'on devices (organization_id, device_identifier) '.
            'where device_identifier is not null'
        );
    }

    public function down(): void
    {
        DB::statement('drop index if exists devices_organization_identifier_unique');

        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn([
                'name', 'vendor', 'device_identifier', 'ip_address', 'port',
                'mac_address', 'hardware_version', 'connection_mode', 'timezone',
                'last_seen_at', 'last_sync_at', 'metadata', 'enabled',
            ]);
        });
    }
};
