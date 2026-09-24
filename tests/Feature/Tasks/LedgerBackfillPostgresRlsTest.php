<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Projects\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskAcceptance;
use App\Domain\Tasks\Models\TaskSubmission;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

pest()->group('tasks', 'acceptance-integrity', 'rls');

/**
 * 03-Construction-Task-Manager-Spec-KA.md §17's historical backfill writes
 * into `task_acceptance_ledger_entries`, which carries FORCE ROW LEVEL
 * SECURITY. Migrations connect as the ordinary non-superuser application
 * role (`oda_app`, DEC-026/DEC-043) and FORCE makes RLS apply to the table's
 * owner too, so every one of those writes needs `app.current_org_id` set —
 * which nothing sets for a `php artisan migrate` run.
 *
 * The whole rest of the Pest suite runs on SQLite, where RLS does not exist,
 * and an empty database never reaches the INSERT at all. Both of those hide
 * this completely: the backfill passed on a fresh database and would have
 * failed on the first real one, with
 * `SQLSTATE[42501] ... new row violates row-level security policy`.
 *
 * So this file runs against the REAL Postgres database as the REAL
 * restricted role, with real historical rows present — the only arrangement
 * in which the bug is visible.
 */
function ledgerBackfillMigration(): object
{
    return require database_path('migrations/2026_09_24_090010_backfill_task_acceptance_ledger.php');
}

function ledgerBackfillSetOrg(?string $organizationId): void
{
    DB::statement("select set_config('app.current_org_id', ?, true)", [$organizationId ?? '']);
}

test('the §17 ledger backfill writes real historical rows under the restricted Postgres role', function () {
    $originalDefault = config('database.default');
    Artisan::call('migrate', ['--database' => 'pgsql_rls_test', '--force' => true]);
    config(['database.default' => 'pgsql_rls_test']);

    // Everything below happens inside one transaction that is always rolled
    // back, so this shared real database keeps no rows from this test.
    DB::beginTransaction();

    try {
        $organization = Organization::factory()->create();
        ledgerBackfillSetOrg($organization->id);

        $performerUser = User::factory()->create([
            'organization_id' => $organization->id,
            'current_organization_id' => $organization->id,
        ]);
        $performer = Employee::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $performerUser->id,
        ]);
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'manager_user_id' => $performerUser->id,
        ]);

        // The §17 shape that must be flagged: a completed task whose only
        // acceptance was signed by the very person who performed the work.
        $task = Task::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'accountable_owner_employee_id' => $performer->id,
            'status' => 'completed',
            'planned_quantity' => '20.00',
            'accepted_quantity' => '20.00',
        ]);
        $submission = TaskSubmission::query()->create([
            'organization_id' => $organization->id,
            'task_id' => $task->id,
            'submitted_by_employee_id' => $performer->id,
            'submitted_quantity' => '20.00',
            'photo_attachment_ids' => [],
            'submitted_at' => now()->subMonth(),
            'status' => 'accepted',
        ]);
        TaskAcceptance::query()->create([
            'organization_id' => $organization->id,
            'task_submission_id' => $submission->id,
            'accepted_by_user_id' => $performerUser->id,
            'accepted_quantity' => '20.00',
            'accepted_at' => now()->subMonth(),
        ]);

        // A migration process has no tenant context — this is the exact
        // ambient state `php artisan migrate` runs in, and the condition
        // under which the original backfill failed.
        ledgerBackfillSetOrg(null);

        ledgerBackfillMigration()->up();

        ledgerBackfillSetOrg($organization->id);

        $entries = DB::table('task_acceptance_ledger_entries')->where('task_id', $task->id)->get();

        expect($entries)->toHaveCount(1)
            ->and($entries[0]->entry_type)->toBe('acceptance')
            ->and((string) $entries[0]->quantity_delta)->toBe('20.00')
            ->and($entries[0]->source_reference)->toStartWith('task_acceptances:')
            // Flagged, not reopened and not re-approved (§17).
            ->and(DB::table('tasks')->where('id', $task->id)->value('status'))->toBe('completed')
            ->and(DB::table('tasks')->where('id', $task->id)->value('legacy_acceptance_unverified'))->toBeTrue();

        // Repeatable: the unique `source_reference` means a second run adds
        // nothing rather than doubling every historical total.
        ledgerBackfillSetOrg(null);
        ledgerBackfillMigration()->up();
        ledgerBackfillSetOrg($organization->id);

        expect(DB::table('task_acceptance_ledger_entries')->where('task_id', $task->id)->count())->toBe(1);
    } finally {
        DB::rollBack();
        config(['database.default' => $originalDefault]);
    }
});

test('writing a ledger entry with no tenant context really is refused by PostgreSQL', function () {
    $originalDefault = config('database.default');
    Artisan::call('migrate', ['--database' => 'pgsql_rls_test', '--force' => true]);
    config(['database.default' => 'pgsql_rls_test']);

    DB::beginTransaction();

    try {
        $organization = Organization::factory()->create();

        // Documents WHY the backfill loops one organization at a time and
        // sets `app.current_org_id` for each: without it, this is the error
        // the migration produces against any database holding real rows.
        ledgerBackfillSetOrg(null);

        expect(fn () => DB::table('task_acceptance_ledger_entries')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'task_id' => (string) Str::uuid(),
            'entry_type' => 'acceptance',
            'quantity_delta' => 1,
            'recorded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            'version' => 1,
        ]))->toThrow(QueryException::class);
    } finally {
        DB::rollBack();
        config(['database.default' => $originalDefault]);
    }
});
