<?php

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\Maintenance;
use App\Domain\Auth\Models\Organization;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

pest()->group('assets');

/**
 * REQ-AST-07 remainder: scheduling/completing service for an asset.
 * Completing service is the one place this restores Asset.condition from
 * 'under_repair' back to 'good' (matching ReturnCustodyAction/
 * ApproveStocktakeVarianceAction's own condition-restoration convention).
 */
beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->manager->assignRole('warehouse_keeper');

    $this->asset = Asset::factory()->create([
        'organization_id' => $this->organization->id,
        'condition' => 'under_repair',
    ]);
});

test('scheduling and completing maintenance restores an under_repair asset to good', function () {
    $this->actingAs($this->manager)->post("/assets/{$this->asset->id}/maintenance", [
        'vendor' => 'Acme Repairs',
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ])->assertRedirect("/assets/{$this->asset->id}");

    CurrentOrganization::set($this->organization->id);
    $maintenance = Maintenance::query()->where('asset_id', $this->asset->id)->sole();
    expect($maintenance->vendor)->toBe('Acme Repairs');
    expect($maintenance->completed_at)->toBeNull();
    expect($this->asset->fresh()->condition)->toBe('under_repair');

    $this->actingAs($this->manager)->post("/assets/maintenance/{$maintenance->id}/complete", [
        'actual_cost' => '45.50',
        'next_service_due_at' => now()->addMonths(6)->toIso8601String(),
    ])->assertRedirect("/assets/{$this->asset->id}");

    CurrentOrganization::set($this->organization->id);
    $maintenance->refresh();
    expect($maintenance->completed_at)->not->toBeNull();
    expect((float) $maintenance->actual_cost)->toBe(45.5);
    expect($this->asset->fresh()->condition)->toBe('good');
});

test('completing maintenance for an asset not currently under_repair does not alter its real condition', function () {
    $goodAsset = Asset::factory()->create(['organization_id' => $this->organization->id, 'condition' => 'good']);
    $maintenance = Maintenance::factory()->create([
        'organization_id' => $this->organization->id,
        'asset_id' => $goodAsset->id,
    ]);

    $this->actingAs($this->manager)->post("/assets/maintenance/{$maintenance->id}/complete", [])
        ->assertRedirect("/assets/{$goodAsset->id}");

    CurrentOrganization::set($this->organization->id);
    expect($goodAsset->fresh()->condition)->toBe('good');
});

test('a user without assets.assets.manage cannot schedule or complete maintenance', function () {
    $employee = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $employee->assignRole('employee');

    $this->actingAs($employee)->post("/assets/{$this->asset->id}/maintenance", ['vendor' => 'x'])
        ->assertForbidden();

    $maintenance = Maintenance::factory()->create([
        'organization_id' => $this->organization->id,
        'asset_id' => $this->asset->id,
    ]);

    $this->actingAs($employee)->post("/assets/maintenance/{$maintenance->id}/complete", [])
        ->assertForbidden();
});

test('a user from a different organization cannot complete another organization\'s maintenance record', function () {
    $otherOrg = Organization::factory()->create();
    $otherAsset = Asset::factory()->create(['organization_id' => $otherOrg->id]);
    $otherMaintenance = Maintenance::factory()->create([
        'organization_id' => $otherOrg->id,
        'asset_id' => $otherAsset->id,
    ]);

    $this->actingAs($this->manager)->post("/assets/maintenance/{$otherMaintenance->id}/complete", [])
        ->assertNotFound();
});
