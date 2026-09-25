<?php

namespace App\Domain\Auth\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Runs a callback with PostgreSQL's `app.current_org_id` pointed at a given
 * organization and puts the previous value back afterwards, whatever
 * happens. Row-level security otherwise silently hides every row of any
 * organization other than the request's own (docs/STATE.md §5) — an empty
 * result would look like an empty table.
 *
 * Only for platform-level code that deliberately works across organizations
 * (the organizations admin screen). A no-op on SQLite, which has no RLS.
 */
class OrganizationDatabaseContext
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function run(string $organizationId, Closure $callback): mixed
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return $callback();
        }

        $previous = DB::selectOne("select current_setting('app.current_org_id', true) as value")?->value;

        DB::statement("select set_config('app.current_org_id', ?, false)", [$organizationId]);

        try {
            return $callback();
        } finally {
            try {
                DB::statement("select set_config('app.current_org_id', ?, false)", [(string) ($previous ?? '')]);
            } catch (QueryException) {
                // Only reachable inside a transaction that already failed:
                // PostgreSQL refuses every statement until the rollback, and
                // that rollback reverts this setting anyway. Swallowing it
                // keeps the original error — the one worth seeing.
            }
        }
    }
}
