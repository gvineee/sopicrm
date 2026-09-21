<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Auth\Models\ProjectMembership;
use App\Domain\DailyJournal\Models\DailyReport;
use App\Domain\Employees\Models\Team;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

pest()->group('daily-journal');

beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->project = Project::factory()->create(['organization_id' => $this->organization->id]);

    $this->foreman = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->foreman->assignRole('foreman');
    ProjectMembership::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'user_id' => $this->foreman->id,
    ]);

    $this->manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->manager->assignRole('project_manager');
    ProjectMembership::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'user_id' => $this->manager->id,
    ]);

    $this->outsider = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->outsider->assignRole('employee');
});

test('foreman creates and submits a daily report, project manager accepts it', function () {
    $this->actingAs($this->foreman)->get(route('daily-journal.create', $this->project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('DailyJournal/Form'));

    $team = Team::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($this->foreman)->post(route('daily-journal.store', $this->project), [
        'report_date' => now()->toDateString(),
        'responsible_user_id' => $this->foreman->id,
        'work_performed_note' => 'Poured foundation slab section A.',
        'team_ids' => [$team->id],
    ])->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $report = DailyReport::query()->where('project_id', $this->project->id)->sole();
    expect($report->status)->toBe('draft');

    $this->actingAs($this->foreman)
        ->get(route('daily-journal.show', [$this->project, $report]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('DailyJournal/Show')
            ->where('report.status', 'draft')
            ->where('can.submit', true)
            ->where('can.accept', false));

    $this->actingAs($this->foreman)->post(route('daily-journal.submit', [$this->project, $report]), [
        'target_version' => $report->version,
    ])->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $report->refresh();
    expect($report->status)->toBe('submitted');
    expect($report->submitted_by_user_id)->toBe($this->foreman->id);

    // Foreman never gets an "accept" permission — a different manager must act.
    $this->actingAs($this->foreman)
        ->post(route('daily-journal.accept', [$this->project, $report]), ['target_version' => $report->version])
        ->assertForbidden();

    $this->actingAs($this->manager)->post(route('daily-journal.accept', [$this->project, $report]), [
        'target_version' => $report->version,
        'notes' => 'Looks good.',
    ])->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $report->refresh();
    expect($report->status)->toBe('accepted');
    expect($report->accepted_by_user_id)->toBe($this->manager->id);
});

test('project manager returns a submitted report with a reason, sending it back to draft', function () {
    // Eloquent does not re-fetch a DB-default column (version defaults to 1
    // via the migration, not an explicit factory value) into the in-memory
    // model post-insert — refresh() is required before reading it, same
    // class of bug as CreateTask's status default (docs/agent-handoff.md).
    $report = DailyReport::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'responsible_user_id' => $this->foreman->id,
        'status' => 'submitted',
        'submitted_at' => now(),
        'submitted_by_user_id' => $this->foreman->id,
    ])->refresh();

    $this->actingAs($this->manager)->post(route('daily-journal.return', [$this->project, $report]), [
        'target_version' => $report->version,
    ])->assertSessionHasErrors('reason');

    $this->actingAs($this->manager)->post(route('daily-journal.return', [$this->project, $report]), [
        'target_version' => $report->version,
        'reason' => 'Missing equipment list.',
    ])->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    expect($report->refresh()->status)->toBe('draft');
});

test('a stale version on submit is rejected with a validation error, not a crash', function () {
    $team = Team::factory()->create(['organization_id' => $this->organization->id]);

    $report = DailyReport::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'responsible_user_id' => $this->foreman->id,
        'status' => 'draft',
        'work_performed_note' => 'Site prep work.',
        'teams_present' => [$team->id],
    ])->refresh();

    $this->actingAs($this->foreman)
        ->post(route('daily-journal.submit', [$this->project, $report]), ['target_version' => $report->version + 1])
        ->assertSessionHasErrors('version');

    CurrentOrganization::set($this->organization->id);
    expect($report->refresh()->status)->toBe('draft');
});

test('a report cannot be reached through an unrelated project url', function () {
    $otherProject = Project::factory()->create(['organization_id' => $this->organization->id]);
    $report = DailyReport::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'responsible_user_id' => $this->foreman->id,
    ]);

    $this->actingAs($this->manager)
        ->get(route('daily-journal.show', [$otherProject, $report]))
        ->assertNotFound();
});

test('a user without dailyjournal.reports.view is forbidden on every route, not just hidden from the menu', function () {
    $report = DailyReport::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'responsible_user_id' => $this->foreman->id,
    ])->refresh();

    // The landing page itself checks dailyjournal.reports.view first thing
    // (DailyReportController::projects()'s own abort_unless) — an outsider
    // with no view permission at all is forbidden here too, not just on the
    // project-nested routes.
    $this->actingAs($this->outsider)->get(route('daily-journal.projects'))->assertForbidden();
    $this->actingAs($this->outsider)->get(route('daily-journal.index', $this->project))->assertForbidden();
    $this->actingAs($this->outsider)->get(route('daily-journal.create', $this->project))->assertForbidden();
    // Every mutating route needs a shape-valid payload here — the
    // FormRequest's own field validation runs before the controller's
    // $this->authorize() call, so an empty body would 302 on a missing
    // required field first and never actually exercise the authorization
    // check this test is for.
    $this->actingAs($this->outsider)->post(route('daily-journal.store', $this->project), [
        'report_date' => now()->toDateString(),
        'responsible_user_id' => $this->foreman->id,
    ])->assertForbidden();
    $this->actingAs($this->outsider)->get(route('daily-journal.show', [$this->project, $report]))->assertForbidden();
    $this->actingAs($this->outsider)->get(route('daily-journal.edit', [$this->project, $report]))->assertForbidden();
    $this->actingAs($this->outsider)->put(route('daily-journal.update', [$this->project, $report]), [
        'target_version' => $report->version,
    ])->assertForbidden();
    $this->actingAs($this->outsider)->post(route('daily-journal.submit', [$this->project, $report]), [
        'target_version' => $report->version,
    ])->assertForbidden();
    $this->actingAs($this->outsider)->post(route('daily-journal.accept', [$this->project, $report]), [
        'target_version' => $report->version,
    ])->assertForbidden();
    $this->actingAs($this->outsider)->post(route('daily-journal.return', [$this->project, $report]), [
        'target_version' => $report->version,
        'reason' => 'n/a',
    ])->assertForbidden();
    $this->actingAs($this->outsider)->get(route('daily-journal.revisions', [$this->project, $report]))->assertForbidden();
});

test('projects landing page only lists projects the user has journal access to', function () {
    $otherProject = Project::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($this->foreman)
        ->get(route('daily-journal.projects'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('DailyJournal/Projects')
            ->has('projects', 1)
            ->where('projects.0.id', $this->project->id));

    expect($otherProject)->not->toBeNull();
});
