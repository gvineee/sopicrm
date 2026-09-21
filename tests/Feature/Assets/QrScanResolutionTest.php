<?php

use App\Domain\Assets\Models\Asset;
use App\Domain\Auth\Models\Organization;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

pest()->group('assets');

/**
 * REQ-AST-02: "QR possession alone grants no access" — a scanned token
 * always re-runs the real AssetPolicy::view check server-side.
 */
beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->viewer = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->viewer->assignRole('warehouse_keeper');

    $this->asset = Asset::factory()->create(['organization_id' => $this->organization->id]);
});

test('a valid token for a viewable asset redirects to the real asset page', function () {
    $response = $this->actingAs($this->viewer)->get("/assets/qr/{$this->asset->qr_token}");

    $response->assertRedirect("/assets/{$this->asset->id}");
});

test('an unknown token 404s', function () {
    $this->actingAs($this->viewer)->get('/assets/qr/does-not-exist')->assertNotFound();
});

test('a user without assets.assets.view gets 404, never 403, for an otherwise-valid token', function () {
    $noPermissionUser = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);

    $response = $this->actingAs($noPermissionUser)->get("/assets/qr/{$this->asset->qr_token}");

    $response->assertNotFound();
});

test('an unauthenticated request is redirected to login, never leaking whether the token is valid', function () {
    $this->get("/assets/qr/{$this->asset->qr_token}")->assertRedirect('/login');
});

test('a token belonging to a different organization 404s', function () {
    $otherOrg = Organization::factory()->create();
    $otherAsset = Asset::factory()->create(['organization_id' => $otherOrg->id]);

    $this->actingAs($this->viewer)->get("/assets/qr/{$otherAsset->qr_token}")->assertNotFound();
});
