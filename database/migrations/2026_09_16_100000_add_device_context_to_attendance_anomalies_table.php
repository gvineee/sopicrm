<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration — Devices module (spec section 6), discovered gap in
 * the P0+P1 schema per docs/architecture.md §3.5 ("a module-building agent
 * that discovers a genuinely missing column/table/index does not edit an
 * existing migration file — it creates a NEW, additive migration").
 *
 * `attendance_anomalies` (2026_09_16_090150_create_attendance_core_tables.php)
 * requires a NOT NULL `employee_id`, which is correct for the Attendance
 * domain's own anomaly types (missing_out, excessive_duration, ...) but
 * spec section 6 also requires flagging `clock_drift`, `out_of_order_events`
 * and `data_gap` anomalies detected purely at the DEVICE/event-stream level
 * — e.g. an unmatched-card event (no resolved employee at all, per spec:
 * "უცნობი ბარათი ... ავტომატურად არ ქმნის თანამშრომელს") or a device clock
 * jump discovered before any event has been attributed to a person. Forcing
 * a fabricated/guessed employee_id onto those rows would violate the hard
 * constraint against inventing data, so this migration:
 *   1. makes `employee_id` nullable,
 *   2. adds a nullable `device_id` FK (which device this anomaly concerns),
 *   3. adds a nullable `raw_access_event_id` FK (which specific ingested
 *      event — if any — triggered detection), so device-level anomalies are
 *      still queryable per device/per event without inventing an employee.
 *
 * Documented as a routine technical decision in docs/decisions.md (Devices
 * module pass).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Laravel 13's native SQLite/Postgres grammars both compile
        // ->change() without doctrine/dbal (SQLite via a table-rebuild,
        // Postgres via a plain ALTER COLUMN) — verified against this repo's
        // actual vendored grammar before relying on it, so no new "Pending
        // dependencies" entry is needed for this migration.
        Schema::table('attendance_anomalies', function (Blueprint $table) {
            $table->uuid('employee_id')->nullable()->change();
        });

        Schema::table('attendance_anomalies', function (Blueprint $table) {
            $table->foreignUuid('device_id')->nullable()->after('employee_id')->constrained()->nullOnDelete();
            $table->foreignUuid('raw_access_event_id')->nullable()->after('device_id')->constrained()->nullOnDelete();

            $table->index(['organization_id', 'device_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_anomalies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('raw_access_event_id');
            $table->dropConstrainedForeignId('device_id');
        });

        Schema::table('attendance_anomalies', function (Blueprint $table) {
            $table->uuid('employee_id')->nullable(false)->change();
        });
    }
};
