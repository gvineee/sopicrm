<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration: two more values on `devices.reader_role`, and the same
 * two on `raw_access_events.reader_direction_snapshot`, which copies it at
 * ingestion time. Nothing is dropped and no existing row changes.
 *
 * The owner's decision for the first live install (2026-09-25): the reader
 * named „აღრიცხვა" exists only to record attendance — the first read of a day
 * is the arrival and the last read is the departure. The gate reader opens the
 * door and is not an attendance reader at all.
 *
 *  - `first_last` — an attendance reader with no direction of its own; a day's
 *    reads are paired as earliest → latest.
 *  - `access_only` — a door reader whose reads are real and kept, but which
 *    deliberately takes no part in attendance. Distinct from `unspecified`,
 *    which means nobody has decided yet and is reported as an anomaly.
 */
return new class extends Migration
{
    private const OLD = ['in', 'out', 'unspecified'];

    private const NEW = ['in', 'out', 'unspecified', 'first_last', 'access_only'];

    public function up(): void
    {
        $this->constrain(self::NEW);
    }

    public function down(): void
    {
        // Reads and readers configured under the new roles go back to „nobody
        // has decided", which is the honest description once the roles no
        // longer exist. The events themselves are kept.
        DB::table('raw_access_events')->whereIn('reader_direction_snapshot', ['first_last', 'access_only'])->update(['reader_direction_snapshot' => 'unspecified']);
        DB::table('devices')->whereIn('reader_role', ['first_last', 'access_only'])->update(['reader_role' => 'unspecified']);

        $this->constrain(self::OLD);
    }

    /**
     * @param  list<string>  $values
     */
    private function constrain(array $values): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Laravel declares an enum on PostgreSQL as varchar + CHECK, so
            // widening it is a constraint swap with no table rewrite.
            $allowed = implode(', ', array_map(fn (string $v) => "'{$v}'", $values));

            foreach (['devices' => 'reader_role', 'raw_access_events' => 'reader_direction_snapshot'] as $table => $column) {
                DB::statement("alter table {$table} drop constraint if exists {$table}_{$column}_check");
                DB::statement("alter table {$table} add constraint {$table}_{$column}_check check ({$column}::text = any (array[{$allowed}]::text[]))");
            }

            return;
        }

        // SQLite (the test database) keeps an enum's CHECK inline in the table
        // definition; `change()` rebuilds the table with the new list.
        Schema::table('devices', function (Blueprint $table) use ($values) {
            $table->enum('reader_role', $values)->default('unspecified')->change();
        });

        Schema::table('raw_access_events', function (Blueprint $table) use ($values) {
            $table->enum('reader_direction_snapshot', $values)->default('unspecified')->change();
        });
    }
};
