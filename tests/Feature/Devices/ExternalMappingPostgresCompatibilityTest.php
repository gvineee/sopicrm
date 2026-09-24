<?php

use App\Domain\Attendance\Models\RawAccessEvent;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Audit A02 (P1): `/device-external-mappings` 500'd in production with
 * `SQLSTATE[42883]: function max(uuid) does not exist` —
 * ExternalIdentifierMappingController aggregated `max(device_id)` over a
 * uuid column, which PostgreSQL has no aggregate for at all. The whole
 * Pest suite runs on SQLite, which happily accepts `max()` on any type, so
 * no existing test could ever have caught it — this file therefore runs
 * against a REAL PostgreSQL connection (the same disposable
 * `pgsql_rls_test` database `tests/Feature/Auth/TenantIsolationRlsTest.php`
 * already establishes).
 *
 * Both assertions below need zero fixture rows on purpose: PostgreSQL
 * rejects `max(uuid)` while PLANNING the statement, so an empty table
 * reproduces the production failure exactly and keeps this guard fast.
 */
function externalMappingPgConnection(): Connection
{
    static $migrated = false;

    $connection = DB::connection('pgsql_rls_test');

    if (! $migrated) {
        Artisan::call('migrate', ['--database' => 'pgsql_rls_test', '--force' => true]);
        $migrated = true;
    }

    return $connection;
}

test('the external-mapping event-stats query is PostgreSQL compatible', function () {
    externalMappingPgConnection();

    // The exact aggregate shape the controller runs — count + min/max over
    // timestamp columns only, never over the uuid device_id.
    $stats = RawAccessEvent::on('pgsql_rls_test')
        ->whereIn('unmatched_credential_ref', ['some-card-ref'])
        ->selectRaw('unmatched_credential_ref, count(*) as event_count, min(normalized_event_time_utc) as first_event_at, max(normalized_event_time_utc) as last_event_at')
        ->groupBy('unmatched_credential_ref')
        ->get();

    expect($stats)->toHaveCount(0);

    // And the replacement "device of the most recent event" query, which
    // resolves the device by TIME with a stable id tie-breaker instead of
    // by uuid magnitude.
    $latest = RawAccessEvent::on('pgsql_rls_test')
        ->whereIn('unmatched_credential_ref', ['some-card-ref'])
        ->orderByDesc('normalized_event_time_utc')
        ->orderByDesc('id')
        ->get(['unmatched_credential_ref', 'device_id']);

    expect($latest)->toHaveCount(0);
});

test('aggregating max() over a uuid column really does fail on PostgreSQL', function () {
    externalMappingPgConnection();

    // Documents WHY the guard above exists: if someone reintroduces
    // `max(device_id)`, this is the production error they will get, and the
    // SQLite-backed rest of the suite will stay green while it happens.
    expect(fn () => RawAccessEvent::on('pgsql_rls_test')
        ->selectRaw('unmatched_credential_ref, max(device_id) as sample_device_id')
        ->groupBy('unmatched_credential_ref')
        ->get()
    )->toThrow(QueryException::class);
});
