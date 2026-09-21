<?php

namespace App\Domain\Shared\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * `ilike` is Postgres-only syntax — every controller search filter that used
 * it directly (Projects/Devices/Credentials/Employees) crashed with a 500 on
 * any non-Postgres connection, which is exactly why none of them had ever
 * been exercised by a test: this whole app's test suite runs on SQLite
 * (`phpunit.xml`), so "search" was structurally untestable before this fix
 * (found while adding a regression test for FIX-02/A2, 2026-09-21). SQLite's
 * `LIKE` is case-insensitive for ASCII by default, so falling back to it
 * there produces the same case-insensitive match Postgres's `ilike` gives in
 * production — this is not a behavior change on Postgres, only a portability
 * fix for the same case-insensitive substring search everywhere else.
 */
final class PortableSearch
{
    /**
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public static function where(Builder $query, string $column, string $term, string $boolean = 'and'): Builder
    {
        return $query->where($column, self::operator(), $term, $boolean);
    }

    /**
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public static function orWhere(Builder $query, string $column, string $term): Builder
    {
        return $query->orWhere($column, self::operator(), $term);
    }

    private static function operator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }
}
