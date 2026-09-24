<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Auth\Models\ProjectMembership;
use App\Domain\DailyJournal\Models\DailyReport;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Audit A12: the daily journal's responsible person was a free-text field
 * labelled "პასუხისმგებელი (User ID)" with the placeholder "UUID", and the
 * value was validated as nothing but a well-formed uuid — so a uuid
 * belonging to another organization was accepted and stored. These tests
 * cover the selector that replaced it and, more importantly, the
 * server-side rule that does not trust the selector's output.
 */
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
        'name' => 'ლევან ბერიძე',
    ]);
    $this->foreman->assignRole('foreman');
    ProjectMembership::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'user_id' => $this->foreman->id,
    ]);

    $this->nonMember = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'name' => 'ნინო ქავთარაძე',
    ]);
    $this->nonMember->assignRole('foreman');

    $this->outsider = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->outsider->assignRole('employee');
});

test('the selector returns people by name and marks who is on this project', function () {
    $response = $this->actingAs($this->foreman)
        ->getJson(route('daily-journal.responsible-users', $this->project))
        ->assertOk();

    $options = collect($response->json('options'))->keyBy('id');

    expect($options->get($this->foreman->id)['label'])->toBe('ლევან ბერიძე');
    expect($options->get($this->foreman->id)['sublabel'])->toBe('პროექტის წევრი');
    expect($options->get($this->nonMember->id)['sublabel'])->toBeNull();
});

test('the selector is gated by the journal\'s own view permission', function () {
    $this->actingAs($this->outsider)
        ->getJson(route('daily-journal.responsible-users', $this->project))
        ->assertForbidden();
});

test('the selector never lists another organization\'s login accounts', function () {
    $otherOrganization = Organization::factory()->create();
    $foreignUser = User::factory()->create([
        'organization_id' => $otherOrganization->id,
        'current_organization_id' => $otherOrganization->id,
        'name' => 'ლევან ბერიძე',
    ]);

    $response = $this->actingAs($this->foreman)
        ->getJson(route('daily-journal.responsible-users', [$this->project, 'q' => 'ლევან']))
        ->assertOk();

    expect(collect($response->json('options'))->pluck('id'))
        ->toContain($this->foreman->id)
        ->not->toContain($foreignUser->id);
});

test('a colleague picked from the selector is accepted', function () {
    // The counterpart to every rejection below. Without it a rule that
    // rejects EVERYTHING would look correct — which is exactly the bug the
    // first version of this rule had (`->where('col', false)` stringifies
    // the boolean to an empty string and matches no row at all).
    $this->actingAs($this->foreman)->post(route('daily-journal.store', $this->project), [
        'report_date' => now()->toDateString(),
        'responsible_user_id' => $this->nonMember->id,
        'work_performed_note' => 'Poured foundation slab section A.',
    ])->assertSessionHasNoErrors()->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $report = DailyReport::query()->where('project_id', $this->project->id)->sole();
    expect($report->responsible_user_id)->toBe($this->nonMember->id);
});

test('a responsible person from another organization is rejected on create', function () {
    $otherOrganization = Organization::factory()->create();
    $foreignUser = User::factory()->create([
        'organization_id' => $otherOrganization->id,
        'current_organization_id' => $otherOrganization->id,
    ]);

    // A well-formed uuid of a real account — exactly what the old
    // `['required','uuid']` rule accepted without question.
    $this->actingAs($this->foreman)->post(route('daily-journal.store', $this->project), [
        'report_date' => now()->toDateString(),
        'responsible_user_id' => $foreignUser->id,
        'work_performed_note' => 'Poured foundation slab section A.',
    ])->assertSessionHasErrors('responsible_user_id');

    CurrentOrganization::set($this->organization->id);
    expect(DailyReport::query()->where('project_id', $this->project->id)->exists())->toBeFalse();
});

test('an invented responsible person is rejected even when it is a valid uuid', function () {
    $this->actingAs($this->foreman)->post(route('daily-journal.store', $this->project), [
        'report_date' => now()->toDateString(),
        'responsible_user_id' => '00000000-0000-4000-8000-000000000000',
    ])->assertSessionHasErrors('responsible_user_id');
});

test('the same rule applies when editing, not only when creating', function () {
    $otherOrganization = Organization::factory()->create();
    $foreignUser = User::factory()->create([
        'organization_id' => $otherOrganization->id,
        'current_organization_id' => $otherOrganization->id,
    ]);

    CurrentOrganization::set($this->organization->id);
    $report = DailyReport::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'responsible_user_id' => $this->foreman->id,
        'status' => 'draft',
    ])->refresh();

    $this->actingAs($this->foreman)->put(route('daily-journal.update', [$this->project, $report]), [
        'target_version' => $report->version,
        'responsible_user_id' => $foreignUser->id,
    ])->assertSessionHasErrors('responsible_user_id');

    CurrentOrganization::set($this->organization->id);
    expect($report->refresh()->responsible_user_id)->toBe($this->foreman->id);
});

test('the edit form receives the chosen person\'s name so the selector can show it', function () {
    $report = DailyReport::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'responsible_user_id' => $this->foreman->id,
        'status' => 'draft',
    ])->refresh();

    $this->actingAs($this->foreman)
        ->get(route('daily-journal.edit', [$this->project, $report]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('DailyJournal/Form')
            ->where('report.responsible_name', 'ლევან ბერიძე'));
});

test('the selector route does not shadow a real report url', function () {
    $report = DailyReport::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'responsible_user_id' => $this->foreman->id,
    ])->refresh();

    // `/{report}` is a single-segment catch-all registered after the literal
    // 'responsible-users' path — this is the A03 class of bug, guarded here
    // before it can happen again.
    $this->actingAs($this->foreman)
        ->get(route('daily-journal.show', [$this->project, $report]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('DailyJournal/Show'));
});
