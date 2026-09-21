<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `devices.port` was declared `unsignedSmallInteger`, which Postgres has no
 * native unsigned type for — Laravel maps it to a plain signed `smallint`
 * (max 32767). Real TCP ports go up to 65535 (confirmed by a real XPass 2's
 * configured device_port 51211 failing to insert with "value out of range
 * for type smallint"), so this widens the column to a full `integer` and
 * adds a check constraint enforcing the actual valid TCP port range instead
 * of just "whatever fits in 2 bytes."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->integer('port')->nullable()->change();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('alter table devices add constraint devices_port_valid_range check (port is null or port between 1 and 65535)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('alter table devices drop constraint if exists devices_port_valid_range');
        }

        Schema::table('devices', function (Blueprint $table) {
            $table->unsignedSmallInteger('port')->nullable()->change();
        });
    }
};
