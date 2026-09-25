<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Auth\Models\ProjectMembership;
use App\Domain\Projects\Actions\AddProjectMemberAction;
use App\Domain\Projects\Actions\TransitionProjectStatusAction;
use App\Domain\Projects\Actions\UpdateProjectAction;
use App\Domain\Projects\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Services\ProjectActivityFeed;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Inertia\Inertia;
use Spatie\Permission\PermissionRegistrar;

pest()->group('projects');

/**
 * Audit A06: the project's „აქტივობა" tab said the activity journal was not
 * implemented. The write side had existed all along — project create/update/
 * status-change, membership add/remove and WBS/document actions have been
 * recording actor, time, reason and before/after into `audit_events` — but no
 * screen, route or resource ever read any of it.
 *
 * These tests cover the read side that was missing, and the two things it
 * must not get wrong: showing a viewer a field they are not cleared for, and
 * showing them another project's history.
 */
beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);

    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->owner = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->owner->assignRole('owner');

    $this->manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'name' => 'ნინო მენეჯერი',
    ]);
    $this->manager->assignRole('project_manager');

    $this->client = Client::factory()->create(['organization_id' => $this->organization->id]);

    $this->project = Project::factory()->create([
        'organization_id' => $this->organization->id,
        'client_id' => $this->client->id,
        'manager_user_id' => $this->manager->id,
        'name' => 'ალფა კოშკი',
        'status' => 'planning',
        'budget_baseline' => '250000.00',
    ]);

    ProjectMembership::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'user_id' => $this->manager->id,
    ]);

    $this->otherProject = Project::factory()->create([
        'organization_id' => $this->organization->id,
        'client_id' => $this->client->id,
        'manager_user_id' => $this->owner->id,
        'name' => 'ბეტა კოშკი',
    ]);
});

test('the feed reports who changed what, and when', function () {
    $this->actingAs($this->owner);

    app(UpdateProjectAction::class)->execute($this->project, ['name' => 'ალფა კოშკი — ეტაპი 2'], $this->owner);
    app(TransitionProjectStatusAction::class)->execute($this->project->refresh(), 'active', 'სამუშაოები დაიწყო', null, $this->owner);

    CurrentOrganization::set($this->organization->id);

    $feed = app(ProjectActivityFeed::class)->for($this->project->refresh(), $this->owner);
    $actions = collect($feed)->pluck('action')->all();

    expect($actions)->toContain('projects.project.updated')
        ->and($actions)->toContain('projects.project.status_changed');

    $statusEntry = collect($feed)->firstWhere('action', 'projects.project.status_changed');

    expect($statusEntry['actor_name'])->toBe($this->owner->name)
        ->and($statusEntry['reason'])->toBe('სამუშაოები დაიწყო')
        ->and($statusEntry['before']['status'])->toBe('planning')
        ->and($statusEntry['after']['status'])->toBe('active')
        ->and($statusEntry['occurred_at'])->not->toBeNull();
});

test('events about the project\'s own children are part of its history', function () {
    $this->actingAs($this->owner);

    $newMember = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);

    app(AddProjectMemberAction::class)->execute($this->project, $newMember->id, 'member', $this->owner);

    CurrentOrganization::set($this->organization->id);

    // A membership is its own row, so the audit event targets the membership,
    // not the project. Without gathering children by id this would be
    // invisible in the project's journal.
    expect(collect(app(ProjectActivityFeed::class)->for($this->project, $this->owner))->pluck('action')->all())
        ->toContain('projects.membership.added');
});

test('a viewer who may not see the budget does not get it through the journal', function () {
    $this->actingAs($this->owner);

    app(UpdateProjectAction::class)->execute($this->project, ['budget_baseline' => '400000.00'], $this->owner);

    CurrentOrganization::set($this->organization->id);

    // Worth being precise: every role that can open a project today
    // (`owner`, `project_manager`) also holds `projects.budget.view`, so this
    // redaction does not fire for anyone right now. It is the guarantee for
    // the viewer roles the spec anticipates — a client or subcontractor who
    // may follow a project without seeing its money — so the test builds
    // exactly that account rather than pretending a role already exists.
    $restrictedViewer = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $restrictedViewer->givePermissionTo('projects.view');
    ProjectMembership::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'user_id' => $restrictedViewer->id,
    ]);

    expect($restrictedViewer->can('viewActivity', $this->project))->toBeTrue()
        ->and($restrictedViewer->can('viewBudget', $this->project))->toBeFalse();

    $entry = collect(app(ProjectActivityFeed::class)->for($this->project->refresh(), $restrictedViewer))
        ->firstWhere('action', 'projects.project.updated');

    // They may see THAT the budget changed and who changed it — the figure
    // itself is what they are not cleared for.
    expect($entry['before']['budget_baseline'])->toBe('***')
        ->and($entry['after']['budget_baseline'])->toBe('***');

    CurrentOrganization::set($this->organization->id);

    $ownerEntry = collect(app(ProjectActivityFeed::class)->for($this->project, $this->owner))
        ->firstWhere('action', 'projects.project.updated');

    expect($ownerEntry['after']['budget_baseline'])->toBe('400000.00');
});

test('one project\'s history never contains another project\'s events', function () {
    $this->actingAs($this->owner);

    app(UpdateProjectAction::class)->execute($this->otherProject, ['name' => 'ბეტა კოშკი — შეცვლილი'], $this->owner);

    CurrentOrganization::set($this->organization->id);

    $ids = collect(app(ProjectActivityFeed::class)->for($this->project, $this->owner))->pluck('target_id')->all();

    expect($ids)->not->toContain($this->otherProject->id);
});

test('the tab is served lazily and only to someone allowed to open the project', function () {
    // Not on an ordinary page load: the history is a query nobody who never
    // opens the tab should pay for.
    $this->actingAs($this->manager)
        ->get(route('projects.show', $this->project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Projects/Show')
            ->missing('activity')
            ->where('project.can.view_activity', true));

    CurrentOrganization::set($this->organization->id);

    // ...and served when the tab asks for it by partial reload.
    $this->actingAs($this->manager)
        ->get(route('projects.show', $this->project), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => Inertia::getVersion(),
            'X-Inertia-Partial-Component' => 'Projects/Show',
            'X-Inertia-Partial-Data' => 'activity',
        ])
        ->assertOk()
        // A partial reload answers with JSON props rather than a full page
        // response, so this is asserted on the payload itself.
        ->assertJsonPath('component', 'Projects/Show')
        ->assertJsonStructure(['props' => ['activity']]);

    CurrentOrganization::set($this->organization->id);

    // A manager who is not a member of this project cannot open it at all,
    // so there is no history for them to reach either.
    $outsider = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $outsider->assignRole('project_manager');

    expect($outsider->can('viewActivity', $this->project))->toBeFalse();
});
