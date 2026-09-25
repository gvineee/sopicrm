<?php

namespace App\Domain\Auth\Support;

use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Works out, from the live schema rather than a hand-kept list, which tables
 * hold an organization's rows and in what order they can be deleted without
 * tripping a foreign key. Used by App\Domain\Auth\Actions\PurgeOrganizationAction.
 *
 * Read-only: it only introspects the schema (`Schema::getTables()`,
 * `hasColumn()`, `getForeignKeys()`), which behaves the same on PostgreSQL and
 * on the SQLite test database. A new migration that adds a tenant table is
 * picked up without anyone remembering to edit this class.
 *
 * Which tables are "owned" by an organization:
 *  - every table with an `organization_id` column;
 *  - a table without one that references an owned table through a CASCADE or
 *    NO ACTION/RESTRICT foreign key (e.g. `passkeys` -> `users`,
 *    `role_has_permissions` -> `roles`) — its rows are selected through the
 *    parent's rows. A SET NULL reference does not make a row owned: the
 *    database detaches it and it stays;
 *  - a table without `organization_id` that points at `organizations` itself.
 *
 * Delete order: a referencing (child) table is deleted before the table it
 * references. Every foreign key constrains that order EXCEPT a single-column
 * ON DELETE SET NULL one: when its parent row goes first, the database simply
 * nulls the child's column, so that edge is safe to ignore — and it has to be,
 * because the schema has cycles made only of such edges (users <-> attachments,
 * employees <-> teams, assets <-> asset_locations). A multi-column SET NULL key
 * is NOT relaxed: nulling it would also null the child's `organization_id`,
 * which is NOT NULL, so the delete would fail. A self-reference is ignored, a
 * single DELETE statement removes all of the table's rows at once. A cycle that
 * remains after all of that is reported as an error rather than guessed at.
 */
class OrganizationPurgePlanner
{
    /**
     * Tables that are infrastructure, not tenant data, and must never be
     * touched even if a future change gives them a matching column.
     *
     * @var list<string>
     */
    private const IGNORED_TABLES = [
        'migrations',
        'organizations',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
    ];

    /**
     * `order` is the delete order (children first). `parents` holds, for a
     * table WITHOUT organization_id, the owning references through which its
     * rows are selected. `organization_columns` names, for a table without
     * organization_id, a column that points straight at organizations.id.
     *
     * @return array{order: list<string>, parents: array<string, list<array{columns: list<string>, table: string, foreign_columns: list<string>}>>, organization_columns: array<string, string>}
     */
    public function plan(): array
    {
        $tables = $this->tableNames();

        /** @var array<string, list<array{columns: list<string>, foreign_table: string, foreign_columns: list<string>, on_delete: string}>> $foreignKeys */
        $foreignKeys = [];
        /** @var array<string, bool> $hasOrganizationId */
        $hasOrganizationId = [];

        foreach ($tables as $table) {
            $hasOrganizationId[$table] = Schema::hasColumn($table, 'organization_id');
            $foreignKeys[$table] = array_map(
                fn (array $fk): array => [
                    'columns' => array_values($fk['columns']),
                    'foreign_table' => $this->bareTableName($fk['foreign_table']),
                    'foreign_columns' => array_values($fk['foreign_columns']),
                    'on_delete' => strtolower((string) ($fk['on_delete'] ?? 'no action')),
                ],
                Schema::getForeignKeys($table),
            );
        }

        // --- 1. Which tables are owned -------------------------------------
        /** @var array<string, true> $owned */
        $owned = [];
        /** @var array<string, string> $organizationColumns */
        $organizationColumns = [];

        foreach ($tables as $table) {
            if ($hasOrganizationId[$table]) {
                $owned[$table] = true;

                continue;
            }

            foreach ($foreignKeys[$table] as $fk) {
                if ($fk['foreign_table'] === 'organizations'
                    && count($fk['columns']) === 1
                    && $fk['on_delete'] !== 'set null') {
                    $owned[$table] = true;
                    $organizationColumns[$table] = $fk['columns'][0];
                }
            }
        }

        /** @var array<string, list<array{columns: list<string>, table: string, foreign_columns: list<string>}>> $parents */
        $parents = [];

        // A table without organization_id can hang off another such table,
        // so keep propagating until nothing new is found.
        do {
            $changed = false;

            foreach ($tables as $table) {
                if ($hasOrganizationId[$table]) {
                    continue;
                }

                foreach ($foreignKeys[$table] as $fk) {
                    $parent = $fk['foreign_table'];

                    if ($parent === $table || ! isset($owned[$parent]) || $fk['on_delete'] === 'set null') {
                        continue;
                    }

                    $reference = ['columns' => $fk['columns'], 'table' => $parent, 'foreign_columns' => $fk['foreign_columns']];

                    if (in_array($reference, $parents[$table] ?? [], true)) {
                        continue;
                    }

                    $parents[$table][] = $reference;
                    $owned[$table] = true;
                    $changed = true;
                }
            }
        } while ($changed);

        // --- 2. Order: children before parents ------------------------------
        /** @var array<string, array<string, true>> $mustPrecede child => [parent => true] */
        $mustPrecede = [];
        foreach (array_keys($owned) as $table) {
            $mustPrecede[$table] = [];

            foreach ($foreignKeys[$table] as $fk) {
                $parent = $fk['foreign_table'];

                if ($parent === $table || ! isset($owned[$parent])) {
                    continue;
                }

                if ($fk['on_delete'] === 'set null' && count($fk['columns']) === 1) {
                    continue;
                }

                $mustPrecede[$table][$parent] = true;
            }
        }

        $order = $this->childrenFirst($mustPrecede);

        return [
            'order' => $order,
            'parents' => $parents,
            'organization_columns' => $organizationColumns,
        ];
    }

    /**
     * Kahn's algorithm, alphabetical among the tables that are ready so the
     * order is stable between runs.
     *
     * @param  array<string, array<string, true>>  $mustPrecede  child => parents it must be deleted before
     * @return list<string>
     */
    private function childrenFirst(array $mustPrecede): array
    {
        /** @var array<string, int> $remainingChildren parent => number of children not yet deleted */
        $remainingChildren = array_fill_keys(array_keys($mustPrecede), 0);
        foreach ($mustPrecede as $parents) {
            foreach (array_keys($parents) as $parent) {
                $remainingChildren[$parent]++;
            }
        }

        $order = [];
        $ready = array_keys(array_filter($remainingChildren, fn (int $count): bool => $count === 0));
        sort($ready);

        while ($ready !== []) {
            $table = array_shift($ready);
            $order[] = $table;

            foreach (array_keys($mustPrecede[$table]) as $parent) {
                $remainingChildren[$parent]--;

                if ($remainingChildren[$parent] === 0) {
                    $ready[] = $parent;
                    sort($ready);
                }
            }
        }

        if (count($order) !== count($mustPrecede)) {
            $stuck = array_values(array_diff(array_keys($mustPrecede), $order));
            sort($stuck);

            throw new RuntimeException(
                'Cannot purge an organization: the foreign keys between these tables form a cycle '.
                'that no delete order satisfies: '.implode(', ', $stuck).'.'
            );
        }

        return $order;
    }

    /**
     * @return list<string>
     */
    private function tableNames(): array
    {
        $schema = Schema::getCurrentSchemaName();
        $names = [];

        foreach (Schema::getTables($schema) as $table) {
            $name = $table['name'];

            if (in_array($name, self::IGNORED_TABLES, true) || str_starts_with($name, 'sqlite_')) {
                continue;
            }

            $names[] = $name;
        }

        sort($names);

        return array_values(array_unique($names));
    }

    private function bareTableName(string $table): string
    {
        $position = strrpos($table, '.');

        return $position === false ? $table : substr($table, $position + 1);
    }
}
