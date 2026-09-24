<?php

namespace Tests\Feature\Tasks\Concerns;

use App\Domain\Auth\Models\ProjectMembership;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Models\Attachment;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskSubmission;
use App\Models\User;

/**
 * Fixture builders for the two-person acceptance suite
 * (03-Construction-Task-Manager-Spec-KA.md stage 1).
 *
 * They live in a trait rather than as file-local Pest helpers because every
 * one of them has to reach the properties the suite's `beforeEach` puts on
 * the test case — the organization, the two independent managers, the
 * performer and their Employee row.
 */
trait BuildsAcceptanceFixture
{
    protected function makeUser(string $role): User
    {
        $user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'current_organization_id' => $this->organization->id,
        ]);

        $user->assignRole($role);

        return $user;
    }

    /**
     * Both managers are made active members of every project the fixture
     * builds: `tasks.tasks.accept` alone is not authority in this codebase,
     * because TaskPolicy::hasProjectAccess() also requires active membership
     * of the specific project unless the user is the owner.
     */
    protected function makeProject(string $name): Project
    {
        $project = Project::factory()->create([
            'organization_id' => $this->organization->id,
            'client_id' => $this->client->id,
            'manager_user_id' => $this->managerA->id,
            'name' => $name,
        ]);

        foreach ([$this->managerA, $this->managerB] as $manager) {
            ProjectMembership::factory()->create([
                'organization_id' => $this->organization->id,
                'project_id' => $project->id,
                'user_id' => $manager->id,
            ]);
        }

        return $project;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeTask(Project $project, array $attributes = []): Task
    {
        return Task::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
            'project_id' => $project->id,
            // The performer is the accountable owner by default, which is
            // what TaskPolicy::submit() requires of whoever submits.
            'accountable_owner_employee_id' => $this->performer->id,
            'status' => 'in_progress',
            'unit' => 'm2',
            // The column defaults to true org-wide. Most rows in this suite
            // are testing who may decide, not what proof was attached, so the
            // requirement is off unless a test turns it back on.
            'requires_photo_evidence' => false,
        ], $attributes));
    }

    /**
     * Submits through the real HTTP route rather than the Action, because
     * most of what stage 1 fixes was reachable only on one transport.
     *
     * @param  list<string>  $attachmentIds
     */
    protected function submitAsPerformer(string $quantity, ?Task $task = null, array $attachmentIds = []): TaskSubmission
    {
        $task ??= $this->task;

        $this->actingAs($this->performerUser)
            ->post(route('projects.tasks.submit', [$task->project_id, $task->id]), [
                'submitted_quantity' => $quantity,
                'attachment_ids' => $attachmentIds,
            ])
            // A failed submit also redirects — back, with errors — so the
            // helper says which rule bit rather than leaving the caller to
            // puzzle over a missing row.
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        // Every request resets the tenant context; the assertions that follow
        // read the database directly, so it has to be put back.
        CurrentOrganization::set($this->organization->id);

        return TaskSubmission::query()
            ->where('task_id', $task->id)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeTaskAttachment(array $attributes = [], ?Task $task = null): Attachment
    {
        $task ??= $this->task;

        return Attachment::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
            'owner_type' => Task::class,
            'owner_id' => $task->id,
            'uploaded_by_user_id' => $this->performerUser->id,
            'status' => 'available',
        ], $attributes));
    }

    /**
     * Re-runs the §17 historical-data migration. It already ran once against
     * an empty database when the suite migrated, so the legacy rows a test
     * creates afterwards need it run again — which is also a real assertion
     * that the migration is safely repeatable (its `source_reference` unique
     * index is what makes that true).
     */
    protected function runLedgerBackfill(): void
    {
        $migration = require database_path('migrations/2026_09_24_090010_backfill_task_acceptance_ledger.php');

        $migration->up();
    }
}
