<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P0+P1 schema pass (docs/data-model.md). `rate_histories` (Employees domain)
 * and `attendance_sessions` (Attendance domain) both need a Postgres
 * `EXCLUDE USING gist` constraint to enforce "no two overlapping active
 * periods" at the DB level (data-model.md hard rules) — GiST equality
 * checks on non-range columns like `employee_id uuid`/`rate_type` require
 * the `btree_gist` extension's operator classes. SQLite (the fast Pest
 * suite's DB, docs/architecture.md §3.5/§3.6) has no equivalent and simply
 * skips those exclusion constraints, same as the existing RLS migration
 * pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('create extension if not exists btree_gist');
    }

    public function down(): void
    {
        // Deliberately not dropped: other tables/extensions in the same
        // database could depend on it, and `DROP EXTENSION` would fail (or
        // cascade-drop unrelated things) — left installed is safe, matching
        // how Postgres extensions are conventionally treated as
        // infrastructure rather than per-migration state.
    }
};
