<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Auth\Models\ProjectMembership;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

pest()->group('notifications', 'search');

beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
});

test('a project_manager only finds projects they are a member of, never another project in the same org', function () {
    $pm = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $pm->assignRole('project_manager');

    $memberProject = Project::factory()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Visible Tower',
    ]);
    ProjectMembership::query()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $memberProject->id,
        'user_id' => $pm->id,
    ]);

    $otherProject = Project::factory()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Hidden Tower',
    ]);

    $response = $this->actingAs($pm)->getJson('/search?q=Tower');
    $response->assertOk();

    $titles = collect($response->json('results'))->pluck('title');

    expect($titles)->toContain('Visible Tower');
    expect($titles)->not->toContain('Hidden Tower');
});

test('a user cannot find a project belonging to another organization', function () {
    $owner = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $owner->assignRole('owner');

    $otherOrganization = Organization::factory()->create();
    CurrentOrganization::set($otherOrganization->id);
    $foreignProject = Project::factory()->create([
        'organization_id' => $otherOrganization->id,
        'name' => 'Foreign Org Project',
    ]);
    CurrentOrganization::set($this->organization->id);

    $response = $this->actingAs($owner)->getJson('/search?q=Foreign');
    $response->assertOk();

    expect(collect($response->json('results'))->pluck('title'))->not->toContain('Foreign Org Project');
});

test('a query shorter than 2 characters returns no results without erroring', function () {
    $owner = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $owner->assignRole('owner');

    $response = $this->actingAs($owner)->getJson('/search?q=a');
    $response->assertOk()->assertJson(['results' => []]);
});
