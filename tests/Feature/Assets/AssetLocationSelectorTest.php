<?php

use App\Domain\Assets\Models\Asset;
use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Models\Site;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Audit A13: registering an asset required hand-typing the initial
 * location's UUID ("საწყობის/ობიექტის UUID"), and `initial_location_id` was
 * validated as nothing but a well-formed uuid — so any uuid at all,
 * including another organization's record, was stored as the asset's
 * location. These tests cover both halves of the fix: a real server-side
 * searchable selector, and a server-side rule that does not trust what it
 * sends back.
 */
pest()->group('assets');

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

    $this->plainEmployeeUser = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->plainEmployeeUser->assignRole('employee');

    $this->site = Site::factory()->create([
        'organization_id' => $this->organization->id,
        'name' => 'ვაკის ობიექტი',
        'is_active' => true,
    ]);
    $this->employee = Employee::factory()->create(['organization_id' => $this->organization->id]);

    $this->assetPayload = fn (array $overrides = []) => array_merge([
        'name' => 'Hilti TE 60',
        'category' => 'power_tools',
        'tracking_type' => 'individual',
        'inventory_code' => 'INV-0001',
        'initial_location_type' => 'site',
        'initial_location_id' => $this->site->id,
        'condition' => 'new',
    ], $overrides);
});

test('the location selector returns named options, never bare identifiers', function () {
    $response = $this->actingAs($this->owner)
        ->getJson(route('assets.location-options', ['type' => 'site', 'q' => 'ვაკის']))
        ->assertOk();

    expect($response->json('options'))->toHaveCount(1);
    expect($response->json('options.0.label'))->toBe('ვაკის ობიექტი');
    expect($response->json('options.0.id'))->toBe($this->site->id);
});

test('the location selector never leaks another organization\'s records', function () {
    $otherOrganization = Organization::factory()->create();
    CurrentOrganization::set($otherOrganization->id);
    $foreignSite = Site::factory()->create([
        'organization_id' => $otherOrganization->id,
        'name' => 'ვაკის საწყობი',
        'is_active' => true,
    ]);
    CurrentOrganization::set($this->organization->id);

    $response = $this->actingAs($this->owner)
        ->getJson(route('assets.location-options', ['type' => 'site', 'q' => 'ვაკის']))
        ->assertOk();

    expect(collect($response->json('options'))->pluck('id'))->not->toContain($foreignSite->id);
});

test('the location selector is gated by the same permission as registering an asset', function () {
    $this->actingAs($this->plainEmployeeUser)
        ->getJson(route('assets.location-options', ['type' => 'site']))
        ->assertForbidden();
});

test('an asset registers against a real site chosen from the selector', function () {
    $this->actingAs($this->owner)
        ->post(route('assets.store'), ($this->assetPayload)())
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $asset = Asset::query()->where('inventory_code', 'INV-0001')->sole();
    expect($asset->currentLocation?->locatable_id)->toBe($this->site->id);
});

test('a location id from another organization is rejected server-side', function () {
    $otherOrganization = Organization::factory()->create();
    CurrentOrganization::set($otherOrganization->id);
    $foreignSite = Site::factory()->create(['organization_id' => $otherOrganization->id]);
    CurrentOrganization::set($this->organization->id);

    // A well-formed uuid of a real row — the old `['required','uuid']` rule
    // accepted exactly this and silently stored a foreign tenant's site as
    // the asset's location.
    $this->actingAs($this->owner)
        ->post(route('assets.store'), ($this->assetPayload)(['initial_location_id' => $foreignSite->id]))
        ->assertSessionHasErrors('initial_location_id');

    CurrentOrganization::set($this->organization->id);
    expect(Asset::query()->where('inventory_code', 'INV-0001')->exists())->toBeFalse();
});

test('an invented location id is rejected even when it is a valid uuid', function () {
    $this->actingAs($this->owner)
        ->post(route('assets.store'), ($this->assetPayload)([
            'initial_location_id' => '00000000-0000-4000-8000-000000000000',
        ]))
        ->assertSessionHasErrors('initial_location_id');
});

test('an employee id is rejected when the chosen type is site, and vice versa', function () {
    $this->actingAs($this->owner)
        ->post(route('assets.store'), ($this->assetPayload)([
            'initial_location_type' => 'site',
            'initial_location_id' => $this->employee->id,
        ]))
        ->assertSessionHasErrors('initial_location_id');

    $this->actingAs($this->owner)
        ->post(route('assets.store'), ($this->assetPayload)([
            'initial_location_type' => 'employee',
            'initial_location_id' => $this->site->id,
        ]))
        ->assertSessionHasErrors('initial_location_id');
});

test('warehouse is not an offerable location type while no warehouse records exist', function () {
    $this->actingAs($this->owner)
        ->post(route('assets.store'), ($this->assetPayload)([
            'initial_location_type' => 'warehouse',
            'initial_location_id' => $this->site->id,
        ]))
        ->assertSessionHasErrors('initial_location_type');
});
