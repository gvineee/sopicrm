<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fixes a real, systemic bug discovered while implementing this pass:
 * docs/architecture.md §5 (DEC-013's neighbor rule) requires "all *_at
 * columns stored in UTC (timestamptz in Postgres)", but every migration
 * written before and during this pass used Laravel's plain
 * `$table->timestamp(...)`/`$table->timestamps()`, which on the `pgsql`
 * grammar compiles to `timestamp WITHOUT time zone`, not `timestamptz` — the
 * opposite of the documented convention. This was only surfaced because
 * `attendance_sessions`' overlap-prevention exclusion constraint
 * (`tstzrange(...)`) failed against real Postgres with "functions in index
 * expression must be marked IMMUTABLE": casting a bare `timestamp` column to
 * `timestamptz` inside an index expression requires the session's `timezone`
 * GUC, so Postgres correctly refuses to index it (that cast is STABLE, not
 * IMMUTABLE) — see docs/decisions.md for the full diagnosis.
 *
 * New migrations in this pass (2026_09_16_090150 onward) were corrected to
 * use `timestampTz()`/`timestampsTz()` directly. This migration retrofits
 * every table created by an EARLIER, already-applied migration (Access
 * domain, cross-cutting attachments/notifications, Employees domain,
 * Devices domain — all migrated to the real dev database before this bug
 * was caught), per docs/architecture.md §3.5's "additive-only after
 * Foundation" rule: rather than editing those already-run migration files,
 * this migration ALTERs the affected columns in place.
 *
 * Implemented generically (via `information_schema.columns`) rather than as
 * a hand-enumerated column list, so it also correctly catches vendor-owned
 * tables in scope of the same convention (spatie/laravel-permission's
 * `roles`/`permissions`/etc., Sanctum's `personal_access_tokens`, Fortify's
 * `passkeys`, and `users` itself) without this migration's author having to
 * transcribe every column name by hand and risk missing one. Framework
 * infrastructure tables that are not part of docs/data-model.md and whose
 * timestamp semantics this convention was never meant to reach (queue/cache
 * internals, the session table already created as a plain
 * `timestamp`-free/int-based table, the migrations ledger itself) are
 * explicitly excluded.
 *
 * `USING "column" AT TIME ZONE 'UTC'` is correct here specifically because
 * every affected value was always written by this same application, which
 * has run entirely in UTC (Laravel's default `app.timezone` is `UTC` and was
 * never changed) since these tables were first migrated moments before this
 * fix — there is no historical non-UTC data to reinterpret.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $excludedTables = [
        'migrations',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'password_reset_tokens',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->timestampColumns() as [$table, $column]) {
            DB::statement(
                "alter table \"{$table}\" alter column \"{$column}\" type timestamptz ".
                "using \"{$column}\" at time zone 'UTC'"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Reverts columns that are CURRENTLY timestamptz back to plain
        // timestamp — the inverse cast, re-normalized through UTC so a
        // down()-then-up() round trip is lossless.
        $placeholders = implode(',', array_fill(0, count($this->excludedTables), '?'));

        $rows = DB::select(<<<SQL
            select table_name, column_name
            from information_schema.columns
            where table_schema = 'public'
              and data_type = 'timestamp with time zone'
              and table_name not in ({$placeholders})
        SQL, $this->excludedTables);

        foreach ($rows as $row) {
            DB::statement(
                "alter table \"{$row->table_name}\" alter column \"{$row->column_name}\" type timestamp ".
                "using \"{$row->column_name}\" at time zone 'UTC'"
            );
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function timestampColumns(): array
    {
        $placeholders = implode(',', array_fill(0, count($this->excludedTables), '?'));

        $rows = DB::select(<<<SQL
            select table_name, column_name
            from information_schema.columns
            where table_schema = 'public'
              and data_type = 'timestamp without time zone'
              and table_name not in ({$placeholders})
        SQL, $this->excludedTables);

        return array_values(array_map(
            fn ($row): array => [(string) $row->table_name, (string) $row->column_name],
            $rows
        ));
    }
};
