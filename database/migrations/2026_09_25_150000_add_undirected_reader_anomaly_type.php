<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Additive migration: one more value on `attendance_anomalies.anomaly_type`.
 * Nothing is dropped and no existing row changes.
 *
 * `undirected_reader` is the condition the first live BioStar install is
 * actually in. Both doors have `exit_device: NONE`, so BioStar itself does not
 * know which reader is an entry and which an exit, and every device therefore
 * carries `reader_role = 'unspecified'`.
 *
 * Reconstruction already refused to build a session from such an event, which
 * is correct — inventing a direction would invent somebody's hours. But it
 * refused SILENTLY: an employee with twenty-one real badge reads showed a
 * completely empty attendance record, with nothing anywhere saying why. That
 * is the failure mode the project's own rule about unknown exits exists to
 * prevent — say „გასვლა დაუდგენელია", never quietly produce nothing.
 *
 * So the absence now has a name, and it names the readers that need
 * configuring rather than leaving somebody to work it out from an empty page.
 */
return new class extends Migration
{
    private const TYPES = [
        'duplicate_in', 'unknown_out', 'missing_out', 'excessive_duration',
        'impossible_site_crossing', 'late_arriving_data', 'clock_drift',
        'out_of_order_events', 'data_gap', 'undirected_reader',
    ];

    public function up(): void
    {
        $this->constrain(self::TYPES);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Anomalies of the removed type are deleted rather than relabelled:
        // calling one of them `data_gap` would be a false statement about the
        // device, and the condition is re-detected on the next reconstruction
        // anyway.
        DB::table('attendance_anomalies')->where('anomaly_type', 'undirected_reader')->delete();

        $this->constrain(array_values(array_diff(self::TYPES, ['undirected_reader'])));
    }

    /**
     * @param  list<string>  $types
     */
    private function constrain(array $types): void
    {
        // SQLite (the test database) cannot alter a CHECK constraint after the
        // fact, so there the column is left as it was declared; the
        // application's own list is what holds on both, and PostgreSQL keeps
        // the belt as well.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $allowed = implode(', ', array_map(fn (string $t) => "'{$t}'", $types));

        DB::statement('alter table attendance_anomalies drop constraint if exists attendance_anomalies_anomaly_type_check');
        DB::statement("alter table attendance_anomalies add constraint attendance_anomalies_anomaly_type_check check (anomaly_type::text = any (array[{$allowed}]::text[]))");
    }
};
