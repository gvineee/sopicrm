<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 03-Construction-Task-Manager-Spec-KA.md §17 historical-data migration.
 *
 * Three things happen here, and one thing deliberately does not.
 *
 * 1. Every existing `task_acceptances` row becomes an `acceptance` ledger
 *    entry carrying a one-time `source_reference` ("task_acceptances:<id>"),
 *    which is UNIQUE — re-running this migration on a database that already
 *    has the rows inserts nothing rather than doubling every total. Every
 *    already-returned submission becomes a zero-delta `return` entry for the
 *    same reason of completeness: the ledger has to explain every submission
 *    that ever reached a decision, not only the accepted ones.
 *
 * 2. Totals are compared before and after. `tasks.accepted_quantity` was
 *    previously maintained as the sum of acceptance rows, so the ledger sum
 *    must reproduce it exactly; any task where it does not is logged by id
 *    with both numbers. The migration does NOT silently "fix" a mismatch by
 *    overwriting the stored number — a discrepancy means the old data has a
 *    story worth reading, and quietly flattening it would destroy the
 *    evidence of whatever caused it.
 *
 * 3. `tasks.legacy_acceptance_unverified` is raised for every task that is
 *    `completed` today but cannot show two independent confirmations: the
 *    accepting user is the submitting person, or is the task's accountable
 *    owner, or there is no acceptance record at all. These tasks KEEP their
 *    completed status (§17 explicit) — they are flagged, not reopened and
 *    not re-approved.
 *
 * What does NOT happen: no approval is invented. A task with no acceptance
 * row does not receive one here, in any form, under any flag.
 *
 * TENANT CONTEXT. Every statement below runs one organization at a time,
 * inside a transaction that first sets `app.current_org_id`. That is not
 * decoration: `task_acceptance_ledger_entries` carries FORCE ROW LEVEL
 * SECURITY, migrations connect as the ordinary non-superuser application
 * role (`oda_app`, DEC-026/DEC-043), and FORCE applies RLS to the table's
 * owner too. Without the GUC set, this migration's INSERTs fail outright
 * with `SQLSTATE[42501] ... new row violates row-level security policy` —
 * verified directly against the real Postgres role, not assumed — and its
 * verification SELECTs would quietly return zero rows and report a clean
 * backfill that never happened. An empty database hides both, which is
 * exactly why this had to be checked against one holding real rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $before = [
            'tasks' => (int) DB::table('tasks')->count(),
            'accepted_sum' => (string) DB::table('tasks')->sum('accepted_quantity'),
        ];

        $report = [
            'ledger_entries_created' => 0,
            'ledger_quantity_delta_sum_after' => '0',
            'tasks_flagged_legacy_unverified' => 0,
            'tasks_with_total_mismatch' => [],
        ];

        foreach ($this->organizationIds() as $organizationId) {
            DB::transaction(function () use ($organizationId, &$report): void {
                $this->enterTenant($organizationId);

                $report['ledger_entries_created'] += $this->importAcceptances($organizationId);
                $report['ledger_entries_created'] += $this->importReturns($organizationId);
                $report['tasks_flagged_legacy_unverified'] += $this->flagUnverifiedLegacyCompletions($organizationId);

                $report['ledger_quantity_delta_sum_after'] = bcadd(
                    $report['ledger_quantity_delta_sum_after'],
                    (string) (DB::table('task_acceptance_ledger_entries')
                        ->where('organization_id', $organizationId)
                        ->sum('quantity_delta') ?: '0'),
                    2,
                );

                $report['tasks_with_total_mismatch'] = array_merge(
                    $report['tasks_with_total_mismatch'],
                    $this->mismatchedTaskIds($organizationId),
                );
            });
        }

        Log::info('Task acceptance ledger backfill', [
            'tasks_examined' => $before['tasks'],
            'tasks_accepted_quantity_sum_before' => $before['accepted_sum'],
            // Reported, never auto-corrected: a mismatch is a fact about the
            // old data that someone needs to read, not something to paper over.
            ...$report,
        ]);
    }

    public function down(): void
    {
        foreach ($this->organizationIds() as $organizationId) {
            DB::transaction(function () use ($organizationId): void {
                $this->enterTenant($organizationId);

                DB::table('task_acceptance_ledger_entries')
                    ->where('organization_id', $organizationId)
                    ->whereNotNull('source_reference')
                    ->delete();
            });
        }

        DB::table('tasks')->update(['legacy_acceptance_unverified' => false]);
    }

    /**
     * @return list<string>
     */
    private function organizationIds(): array
    {
        return array_values(array_map(strval(...), DB::table('organizations')->orderBy('id')->pluck('id')->all()));
    }

    /**
     * Transaction-scoped (`is_local = true`), so the context cannot leak past
     * this migration's own commit onto a pooled connection.
     */
    private function enterTenant(string $organizationId): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("select set_config('app.current_org_id', ?, true)", [$organizationId]);
    }

    private function importAcceptances(string $organizationId): int
    {
        $created = 0;

        DB::table('task_acceptances')
            ->join('task_submissions', 'task_submissions.id', '=', 'task_acceptances.task_submission_id')
            ->where('task_acceptances.organization_id', $organizationId)
            ->select([
                'task_acceptances.id',
                'task_acceptances.organization_id',
                'task_acceptances.accepted_by_user_id',
                'task_acceptances.accepted_quantity',
                'task_acceptances.accepted_at',
                'task_acceptances.notes',
                'task_acceptances.task_submission_id',
                'task_submissions.task_id',
            ])
            ->orderBy('task_acceptances.accepted_at')
            ->chunk(500, function ($rows) use (&$created): void {
                $now = now();

                $payload = $rows
                    ->reject(fn ($row) => DB::table('task_acceptance_ledger_entries')
                        ->where('source_reference', "task_acceptances:{$row->id}")
                        ->exists())
                    ->map(fn ($row) => [
                        'id' => (string) Str::uuid(),
                        'organization_id' => $row->organization_id,
                        'task_id' => $row->task_id,
                        'task_submission_id' => $row->task_submission_id,
                        'entry_type' => 'acceptance',
                        'quantity_delta' => $row->accepted_quantity ?? 0,
                        'actor_user_id' => $row->accepted_by_user_id,
                        'actor_employee_id' => DB::table('employees')->where('user_id', $row->accepted_by_user_id)->value('id'),
                        'reason' => $row->notes,
                        'source_reference' => "task_acceptances:{$row->id}",
                        'recorded_at' => $row->accepted_at,
                        'created_at' => $now,
                        'updated_at' => $now,
                        'version' => 1,
                    ])
                    ->values()
                    ->all();

                if ($payload !== []) {
                    DB::table('task_acceptance_ledger_entries')->insert($payload);
                    $created += count($payload);
                }
            });

        return $created;
    }

    private function importReturns(string $organizationId): int
    {
        $created = 0;

        DB::table('task_submissions')
            ->where('organization_id', $organizationId)
            ->where('status', 'returned')
            ->orderBy('updated_at')
            ->chunk(500, function ($rows) use (&$created): void {
                $now = now();

                $payload = $rows
                    ->reject(fn ($row) => DB::table('task_acceptance_ledger_entries')
                        ->where('source_reference', "task_submissions:{$row->id}")
                        ->exists())
                    ->map(fn ($row) => [
                        'id' => (string) Str::uuid(),
                        'organization_id' => $row->organization_id,
                        'task_id' => $row->task_id,
                        'task_submission_id' => $row->id,
                        'entry_type' => 'return',
                        'quantity_delta' => 0,
                        // Pre-ledger returns did not record WHO returned
                        // them anywhere queryable. Left null rather than
                        // guessed — an invented reviewer is exactly the kind
                        // of fabricated history §17 forbids.
                        'actor_user_id' => null,
                        'actor_employee_id' => null,
                        'reason' => $row->returned_reason,
                        'source_reference' => "task_submissions:{$row->id}",
                        'recorded_at' => $row->updated_at,
                        'created_at' => $now,
                        'updated_at' => $now,
                        'version' => 1,
                    ])
                    ->values()
                    ->all();

                if ($payload !== []) {
                    DB::table('task_acceptance_ledger_entries')->insert($payload);
                    $created += count($payload);
                }
            });

        return $created;
    }

    private function flagUnverifiedLegacyCompletions(string $organizationId): int
    {
        $flagged = 0;

        DB::table('tasks')
            ->where('organization_id', $organizationId)
            ->where('status', 'completed')
            ->orderBy('id')
            ->chunk(500, function ($tasks) use (&$flagged): void {
                foreach ($tasks as $task) {
                    $decisions = DB::table('task_acceptances')
                        ->join('task_submissions', 'task_submissions.id', '=', 'task_acceptances.task_submission_id')
                        ->where('task_submissions.task_id', $task->id)
                        ->select([
                            'task_acceptances.accepted_by_user_id',
                            'task_submissions.submitted_by_employee_id',
                        ])
                        ->get();

                    if ($decisions->isEmpty()) {
                        $this->flag($task->id);
                        $flagged++;

                        continue;
                    }

                    foreach ($decisions as $decision) {
                        $reviewerEmployeeId = DB::table('employees')
                            ->where('user_id', $decision->accepted_by_user_id)
                            ->value('id');

                        $reviewerIsPerformer = $reviewerEmployeeId !== null
                            && ($reviewerEmployeeId === $decision->submitted_by_employee_id
                                || $reviewerEmployeeId === $task->accountable_owner_employee_id);

                        if ($reviewerIsPerformer) {
                            $this->flag($task->id);
                            $flagged++;

                            break;
                        }
                    }
                }
            });

        return $flagged;
    }

    private function flag(string $taskId): void
    {
        DB::table('tasks')->where('id', $taskId)->update(['legacy_acceptance_unverified' => true]);
    }

    /**
     * @return list<string>
     */
    private function mismatchedTaskIds(string $organizationId): array
    {
        return array_values(array_map(strval(...), DB::table('tasks')
            ->leftJoin('task_acceptance_ledger_entries', 'task_acceptance_ledger_entries.task_id', '=', 'tasks.id')
            ->where('tasks.organization_id', $organizationId)
            ->groupBy('tasks.id', 'tasks.accepted_quantity')
            ->havingRaw('coalesce(sum(task_acceptance_ledger_entries.quantity_delta), 0) <> tasks.accepted_quantity')
            ->pluck('tasks.id')
            ->all()));
    }
};
