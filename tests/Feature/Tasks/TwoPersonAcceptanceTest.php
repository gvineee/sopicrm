<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Auth\Models\ProjectMembership;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\Team;
use App\Domain\Projects\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Tasks\Models\ChecklistItem;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskAcceptance;
use App\Domain\Tasks\Models\TaskAcceptanceLedgerEntry;
use App\Domain\Tasks\Models\TaskAssignee;
use App\Domain\Tasks\Models\TaskSubmission;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Tasks\Concerns\BuildsAcceptanceFixture;

pest()->group('tasks', 'acceptance-integrity')->use(BuildsAcceptanceFixture::class);

/**
 * 03-Construction-Task-Manager-Spec-KA.md stage 1 (§17), acceptance rows
 * DV-03..DV-06, DV-08, EV-01..EV-04, SEC-01, SEC-02, SEC-05, MIG-01, MIG-02.
 *
 * The central rule under test: final acceptance of performed work takes two
 * DIFFERENT REAL PEOPLE. The fixture below is deliberately not the
 * owner-only shape the older task tests use — it has two organizations,
 * three projects, two independent managers, three performers, a brigade, a
 * contractor and an Employee with no login at all, because almost every way
 * this rule can be broken involves someone who is not the owner.
 */
beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);

    $this->organization = Organization::factory()->create();
    $this->otherOrganization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->client = Client::factory()->create(['organization_id' => $this->organization->id]);

    $this->owner = $this->makeUser('owner');
    // Two managers who are independent of each other and of the work: the
    // point of having two is that "an authorized reviewer exists" and "this
    // particular reviewer is independent" are different questions.
    $this->managerA = $this->makeUser('project_manager');
    $this->managerB = $this->makeUser('project_manager');

    $this->brigade = Team::factory()->create(['organization_id' => $this->organization->id]);

    $this->performerUser = $this->makeUser('employee');
    $this->performer = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->performerUser->id,
    ]);

    $this->mateUser = $this->makeUser('employee');
    $this->mate = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->mateUser->id,
        'team_id' => $this->brigade->id,
    ]);

    // A real construction crew always contains someone with no account at
    // all; their participation still has to land in the snapshot.
    $this->accountlessEmployee = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => null,
        'team_id' => $this->brigade->id,
    ]);

    $this->project = $this->makeProject('Main Site');
    $this->secondProject = $this->makeProject('Second Site');
    $this->thirdProject = $this->makeProject('Third Site');

    $this->task = $this->makeTask($this->project, ['planned_quantity' => '100.00', 'unit' => 'm2']);
});

test('DV-02: someone the task was never given to cannot start or submit it, and it cannot be submitted before it is started', function () {
    $draft = $this->makeTask($this->project, ['planned_quantity' => '10.00', 'status' => 'draft']);

    // A colleague with a real employee record, simply not this task's
    // performer: neither working on it nor submitting it is open to them.
    expect($this->mateUser->can('work', $draft))->toBeFalse();

    $this->actingAs($this->mateUser)
        ->post(route('projects.tasks.start', [$this->project, $draft]))
        ->assertForbidden();

    $this->actingAs($this->mateUser)
        ->post(route('projects.tasks.submit', [$this->project, $draft]), ['submitted_quantity' => '10.00'])
        ->assertForbidden();

    // And the real performer cannot skip the workflow either: a task nobody
    // has started is not a task anyone can report as done.
    CurrentOrganization::set($this->organization->id);
    $this->actingAs($this->performerUser)
        ->post(route('projects.tasks.submit', [$this->project, $draft]), ['submitted_quantity' => '10.00'])
        ->assertSessionHasErrors('status');

    CurrentOrganization::set($this->organization->id);
    expect($draft->refresh()->status)->toBe('draft')
        ->and(TaskSubmission::query()->where('task_id', $draft->id)->count())->toBe(0);
});

test('DV-03: the performer cannot accept their own submission on any transport, self_close_allowed or not', function () {
    // The flag is switched ON deliberately: under the old rule this exact
    // row was the authorized self-close case (TM-01).
    $this->task->update(['self_close_allowed' => true]);
    $submission = $this->submitAsPerformer('40.00');

    $this->actingAs($this->performerUser)
        ->post(route('projects.tasks.submissions.accept', [$this->project, $this->task, $submission]), [
            'accepted_quantity' => '40.00',
        ])
        ->assertForbidden();

    // ...and the Policy itself, which is what the Show page's buttons read.
    expect($this->performerUser->can('acceptSubmission', [$this->task, $submission]))->toBeFalse()
        ->and($this->performerUser->can('returnSubmission', [$this->task, $submission]))->toBeFalse();

    CurrentOrganization::set($this->organization->id);
    expect(TaskAcceptance::query()->count())->toBe(0)
        ->and($this->task->refresh()->status)->toBe('submitted');
});

test('DV-04: an assignee who also holds the manager role cannot review the submission they worked on', function () {
    // The mate is a performer on THIS task and a project manager everywhere.
    $this->mateUser->assignRole('project_manager');
    TaskAssignee::query()->create([
        'organization_id' => $this->organization->id,
        'task_id' => $this->task->id,
        'employee_id' => $this->mate->id,
    ]);

    $submission = $this->submitAsPerformer('40.00');

    expect($this->mateUser->can('acceptSubmission', [$this->task, $submission]))->toBeFalse();

    $this->actingAs($this->mateUser)
        ->post(route('projects.tasks.submissions.accept', [$this->project, $this->task, $submission]), [
            'accepted_quantity' => '40.00',
        ])
        ->assertForbidden();

    // The independent manager, who did none of the work, still can. The
    // tenant context has to be restored first: the Policy reads the task's
    // project through the tenant scope, and the request above left it unset.
    CurrentOrganization::set($this->organization->id);
    expect($this->managerA->can('acceptSubmission', [$this->task, $submission]))->toBeTrue();
});

test('DV-04: a brigade member reviewing work their brigade performed is refused, including the crew member with no login', function () {
    TaskAssignee::query()->create([
        'organization_id' => $this->organization->id,
        'task_id' => $this->task->id,
        'team_id' => $this->brigade->id,
    ]);

    $submission = $this->submitAsPerformer('40.00');

    $snapshot = $submission->participant_snapshot;

    expect($snapshot['employee_ids'])->toContain($this->mate->id)
        ->and($snapshot['employee_ids'])->toContain($this->accountlessEmployee->id)
        ->and($snapshot['user_ids'])->toContain($this->performerUser->id);

    // Give the brigade member manager powers after the fact — the frozen
    // snapshot still remembers that they were on the crew.
    $this->mateUser->assignRole('project_manager');

    expect($this->mateUser->can('acceptSubmission', [$this->task, $submission]))->toBeFalse();
});

test('DV-05: re-pointing the account behind a submission does not launder the person who made it', function () {
    $submission = $this->submitAsPerformer('40.00');

    // The same human, now reaching the system through a manager-shaped
    // account: the Employee link is moved away and manager powers granted.
    // The submission's frozen `user_ids` still names this login.
    $this->performer->update(['user_id' => null]);
    $this->performerUser->assignRole('project_manager');
    ProjectMembership::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'user_id' => $this->performerUser->id,
    ]);

    expect($this->performerUser->fresh()->can('acceptSubmission', [$this->task, $submission]))->toBeFalse();
});

test('DV-06: the organization owner cannot accept their own submitted work — owner bypass does not apply', function () {
    $ownerEmployee = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->owner->id,
    ]);
    $ownerTask = $this->makeTask($this->project, [
        'accountable_owner_employee_id' => $ownerEmployee->id,
        'planned_quantity' => '10.00',
    ]);

    $this->actingAs($this->owner)
        ->post(route('projects.tasks.submit', [$this->project, $ownerTask]), ['submitted_quantity' => '10.00'])
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $submission = $ownerTask->submissions()->sole();

    $this->actingAs($this->owner)
        ->post(route('projects.tasks.submissions.accept', [$this->project, $ownerTask, $submission]), [
            'accepted_quantity' => '10.00',
        ])
        ->assertForbidden();

    CurrentOrganization::set($this->organization->id);
    expect($ownerTask->refresh()->status)->toBe('submitted');
});

test('DV-08: a forged actor, organization or timestamp in the request body is ignored', function () {
    $submission = $this->submitAsPerformer('40.00');

    $this->actingAs($this->managerA)
        ->post(route('projects.tasks.submissions.accept', [$this->project, $this->task, $submission]), [
            'accepted_quantity' => '40.00',
            // All four are client-sent lies about who decided, for whom and when.
            'accepted_by_user_id' => $this->performerUser->id,
            'accepted_by' => $this->performerUser->id,
            'organization_id' => $this->otherOrganization->id,
            'accepted_at' => '1999-01-01T00:00:00+00:00',
        ])
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $acceptance = TaskAcceptance::query()->sole();

    expect($acceptance->accepted_by_user_id)->toBe($this->managerA->id)
        ->and($acceptance->organization_id)->toBe($this->organization->id)
        ->and($acceptance->accepted_at->year)->toBe(now()->year);
});

test('EV-01: the web submit really sends its evidence ids, and the reviewer sees the same files', function () {
    $photo = $this->makeTaskAttachment(['mime_type' => 'image/jpeg']);

    // The Show page must offer the file as selectable evidence in the first place.
    $this->actingAs($this->performerUser)
        ->get(route('projects.tasks.show', [$this->project, $this->task]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tasks/Show')
            ->where('task.attachments.0.id', $photo->id)
            ->where('task.attachments.0.is_photo', true)
            ->where('task.attachments.0.selectable_as_evidence', true));

    $this->actingAs($this->performerUser)
        ->post(route('projects.tasks.submit', [$this->project, $this->task]), [
            'submitted_quantity' => '40.00',
            'attachment_ids' => [$photo->id],
        ])
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $submission = $this->task->submissions()->sole();

    expect($submission->photo_attachment_ids)->toBe([$photo->id])
        ->and($submission->evidence_snapshot[0]['is_photo'])->toBeTrue();

    // And the reviewer opening the page sees that same file under the submission.
    $this->actingAs($this->managerA)
        ->get(route('projects.tasks.show', [$this->project, $this->task]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('task.submissions.0.photos.0.id', $photo->id));
});

test('EV-02: a PDF does not satisfy a photo requirement', function () {
    $this->task->update(['requires_photo_evidence' => true, 'min_required_photos' => 1]);
    $pdf = $this->makeTaskAttachment(['mime_type' => 'application/pdf', 'original_filename' => 'certificate.pdf']);

    $this->actingAs($this->performerUser)
        ->post(route('projects.tasks.submit', [$this->project, $this->task]), [
            'submitted_quantity' => '40.00',
            'attachment_ids' => [$pdf->id],
        ])
        ->assertSessionHasErrors('attachments');

    CurrentOrganization::set($this->organization->id);
    expect($this->task->refresh()->status)->toBe('in_progress')
        ->and(TaskSubmission::query()->count())->toBe(0)
        // The PDF is still owned by the task, so the performer can retry
        // without re-uploading anything.
        ->and($pdf->refresh()->owner_id)->toBe($this->task->id);
});

test('EV-03: evidence that is still uploading, or belongs to another task, blocks the submission without losing the draft', function () {
    $this->task->update(['requires_photo_evidence' => false]);

    $stillUploading = $this->makeTaskAttachment(['status' => 'uploaded']);

    $this->actingAs($this->performerUser)
        ->post(route('projects.tasks.submit', [$this->project, $this->task]), [
            'submitted_quantity' => '40.00',
            'attachment_ids' => [$stillUploading->id],
        ])
        ->assertSessionHasErrors('attachments');

    $foreignTask = $this->makeTask($this->secondProject, ['planned_quantity' => '10.00']);
    $foreignPhoto = $this->makeTaskAttachment(['mime_type' => 'image/jpeg'], $foreignTask);

    $this->actingAs($this->performerUser)
        ->post(route('projects.tasks.submit', [$this->project, $this->task]), [
            'submitted_quantity' => '40.00',
            'attachment_ids' => [$foreignPhoto->id],
        ])
        ->assertSessionHasErrors('attachments');

    CurrentOrganization::set($this->organization->id);
    expect($this->task->refresh()->status)->toBe('in_progress')
        ->and(TaskSubmission::query()->count())->toBe(0);
});

test('EV-04: the checklist freezes at submission time and cannot be edited while a reviewer is looking at it', function () {
    $item = ChecklistItem::query()->create([
        'organization_id' => $this->organization->id,
        'task_id' => $this->task->id,
        'label' => 'Rebar spacing verified',
        'is_required' => true,
        'is_checked' => true,
        'checked_by_user_id' => $this->performerUser->id,
        'checked_at' => now(),
    ]);

    // The WHOLE planned volume, so that the acceptance below really closes
    // the task. Accepting a part of it would leave the task in_progress —
    // which is TM-05 working correctly, and would make the second half of
    // this test assert the wrong thing.
    $submission = $this->submitAsPerformer('100.00');

    expect($submission->checklist_snapshot)->toHaveCount(1)
        ->and($submission->checklist_snapshot[0]['is_checked'])->toBeTrue()
        ->and($submission->checklist_snapshot[0]['label'])->toBe('Rebar spacing verified');

    // Un-ticking it mid-review is refused outright...
    $this->actingAs($this->performerUser)
        ->patch(route('projects.tasks.checklist-items.update', [$this->project, $this->task, $item]), ['is_checked' => false])
        ->assertSessionHasErrors('checklist');

    CurrentOrganization::set($this->organization->id);
    expect($item->refresh()->is_checked)->toBeTrue();

    // ...and after a decision, the task is completed and still closed to edits.
    $this->actingAs($this->managerA)
        ->post(route('projects.tasks.submissions.accept', [$this->project, $this->task, $submission]), [
            'accepted_quantity' => '100.00',
        ])
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    expect($this->task->refresh()->status)->toBe('completed');

    $this->actingAs($this->performerUser)
        ->patch(route('projects.tasks.checklist-items.update', [$this->project, $this->task, $item]), ['is_checked' => false])
        ->assertSessionHasErrors('checklist');

    CurrentOrganization::set($this->organization->id);
    expect($submission->refresh()->checklist_snapshot[0]['is_checked'])->toBeTrue();
});

test('SEC-01: a submission id from another task cannot be decided through this task URL', function () {
    $submission = $this->submitAsPerformer('40.00');

    $otherTask = $this->makeTask($this->secondProject, ['planned_quantity' => '50.00']);

    $this->actingAs($this->managerA)
        ->post(route('projects.tasks.submissions.accept', [$this->secondProject, $otherTask, $submission]), [
            'accepted_quantity' => '40.00',
        ])
        ->assertNotFound();

    // Same task id, wrong project: the parent chain has to hold end to end.
    $this->actingAs($this->managerA)
        ->post(route('projects.tasks.submissions.accept', [$this->thirdProject, $this->task, $submission]), [
            'accepted_quantity' => '40.00',
        ])
        ->assertNotFound();

    CurrentOrganization::set($this->organization->id);
    expect(TaskAcceptance::query()->count())->toBe(0);
});

test('SEC-02: another tenant\'s manager cannot see or decide this task', function () {
    $submission = $this->submitAsPerformer('40.00');

    $outsider = User::factory()->create([
        'organization_id' => $this->otherOrganization->id,
        'current_organization_id' => $this->otherOrganization->id,
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->otherOrganization->id);
    $outsider->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->actingAs($outsider)
        ->get(route('projects.tasks.show', [$this->project, $this->task]))
        ->assertNotFound();

    $this->actingAs($outsider)
        ->post(route('projects.tasks.submissions.accept', [$this->project, $this->task, $submission]), [
            'accepted_quantity' => '40.00',
        ])
        ->assertNotFound();

    CurrentOrganization::set($this->organization->id);
    expect(TaskAcceptance::query()->count())->toBe(0);
});

test('SEC-05: a decision carrying a stale expected_version is refused instead of silently overwriting', function () {
    $submission = $this->submitAsPerformer('40.00');
    $staleVersion = (int) $submission->version;

    // Someone else moves the submission on first.
    $this->actingAs($this->managerA)
        ->post(route('projects.tasks.submissions.return', [$this->project, $this->task, $submission]), [
            'reason' => 'ფოტოზე არ ჩანს არმატურა',
        ])
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);

    $this->actingAs($this->managerB)
        ->post(route('projects.tasks.submissions.accept', [$this->project, $this->task, $submission]), [
            'accepted_quantity' => '40.00',
            'expected_version' => $staleVersion,
        ])
        ->assertSessionHasErrors('status');

    CurrentOrganization::set($this->organization->id);
    expect($submission->refresh()->status)->toBe('returned')
        ->and(TaskAcceptance::query()->count())->toBe(0);
});

test('SEC-03: accept and return on one submission resolve to exactly one final decision', function () {
    $submission = $this->submitAsPerformer('40.00');

    $this->actingAs($this->managerA)
        ->post(route('projects.tasks.submissions.accept', [$this->project, $this->task, $submission]), [
            'accepted_quantity' => '35.00',
        ])
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);

    // The second reviewer's return arrives after the accept already landed.
    $this->actingAs($this->managerB)
        ->post(route('projects.tasks.submissions.return', [$this->project, $this->task, $submission]), [
            'reason' => 'მაინც არ მომწონს',
        ])
        ->assertSessionHasErrors('status');

    CurrentOrganization::set($this->organization->id);
    expect(TaskAcceptanceLedgerEntry::query()->where('task_submission_id', $submission->id)->count())->toBe(1)
        ->and($submission->refresh()->status)->toBe('accepted');
});

test('MIG-01: a legacy completed task with no independent verification keeps its status, is flagged, and gains no fabricated approval', function () {
    $legacyTask = $this->makeTask($this->project, ['planned_quantity' => '20.00', 'status' => 'completed', 'accepted_quantity' => '20.00']);

    $legacySubmission = TaskSubmission::query()->create([
        'organization_id' => $this->organization->id,
        'task_id' => $legacyTask->id,
        'submitted_by_employee_id' => $this->performer->id,
        'submitted_quantity' => '20.00',
        'photo_attachment_ids' => [],
        'submitted_at' => now()->subMonth(),
        'status' => 'accepted',
    ]);
    // The old self-close shape: the performer accepted their own work.
    TaskAcceptance::query()->create([
        'organization_id' => $this->organization->id,
        'task_submission_id' => $legacySubmission->id,
        'accepted_by_user_id' => $this->performerUser->id,
        'accepted_quantity' => '20.00',
        'accepted_at' => now()->subMonth(),
    ]);

    $this->runLedgerBackfill();

    CurrentOrganization::set($this->organization->id);
    $legacyTask->refresh();

    expect($legacyTask->status)->toBe('completed')
        ->and($legacyTask->legacy_acceptance_unverified)->toBeTrue()
        // Exactly one ledger row, derived from the one real acceptance —
        // nothing invented to make the history look compliant.
        ->and(TaskAcceptanceLedgerEntry::query()->where('task_id', $legacyTask->id)->count())->toBe(1)
        ->and(TaskAcceptance::query()->where('task_submission_id', $legacySubmission->id)->count())->toBe(1);

    $entry = TaskAcceptanceLedgerEntry::query()->where('task_id', $legacyTask->id)->sole();
    expect($entry->source_reference)->toStartWith('task_acceptances:')
        ->and((string) $entry->quantity_delta)->toBe('20.00');

    // The page says so out loud rather than presenting it as verified.
    $this->actingAs($this->managerA)
        ->get(route('projects.tasks.show', [$this->project, $legacyTask]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('task.legacy_acceptance_unverified', true));
});

test('MIG-01: a legacy completed task accepted by an independent manager is NOT flagged', function () {
    $cleanTask = $this->makeTask($this->project, ['planned_quantity' => '20.00', 'status' => 'completed', 'accepted_quantity' => '20.00']);

    $submission = TaskSubmission::query()->create([
        'organization_id' => $this->organization->id,
        'task_id' => $cleanTask->id,
        'submitted_by_employee_id' => $this->performer->id,
        'submitted_quantity' => '20.00',
        'photo_attachment_ids' => [],
        'submitted_at' => now()->subMonth(),
        'status' => 'accepted',
    ]);
    TaskAcceptance::query()->create([
        'organization_id' => $this->organization->id,
        'task_submission_id' => $submission->id,
        'accepted_by_user_id' => $this->managerA->id,
        'accepted_quantity' => '20.00',
        'accepted_at' => now()->subMonth(),
    ]);

    $this->runLedgerBackfill();

    CurrentOrganization::set($this->organization->id);
    expect($cleanTask->refresh()->legacy_acceptance_unverified)->toBeFalse();
});

test('MIG-02: an active task carrying the old self_close_allowed flag is governed by the strict rule', function () {
    $this->task->update(['self_close_allowed' => true]);
    $submission = $this->submitAsPerformer('40.00');

    // The historical column is untouched — §17 keeps it as a record — but
    // nothing in the workflow consults it any more.
    expect($this->task->refresh()->self_close_allowed)->toBeTrue()
        ->and($this->performerUser->can('acceptSubmission', [$this->task, $submission]))->toBeFalse()
        ->and($this->managerA->can('acceptSubmission', [$this->task, $submission]))->toBeTrue();

    $this->actingAs($this->managerA)
        ->post(route('projects.tasks.submissions.accept', [$this->project, $this->task, $submission]), [
            'accepted_quantity' => '40.00',
        ])
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    expect(TaskAcceptance::query()->sole()->accepted_by_user_id)->toBe($this->managerA->id);
});

/*
 * The fixture builders this suite leans on live in
 * Tests\Feature\Tasks\Concerns\BuildsAcceptanceFixture, wired up by the
 * pest()->use() call at the top of this file.
 */
