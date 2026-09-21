<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
});

test('/health is public and reports real dependency checks', function () {
    $response = $this->get('/health');

    $response->assertOk();
    $response->assertJson(['status' => 'ok']);
    $response->assertJsonStructure(['status', 'checks' => ['database', 'redis']]);
});

test('/ready is rejected for an unauthenticated caller', function () {
    $this->get('/ready')->assertForbidden();
});

test('/ready is rejected for an authenticated non-platform-admin', function () {
    $user = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'is_platform_admin' => false,
    ]);

    $this->actingAs($user)->get('/ready')->assertForbidden();
});

test('/ready reports real operational data for a platform admin', function () {
    $admin = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'is_platform_admin' => true,
    ]);

    $response = $this->actingAs($admin)->get('/ready');

    $response->assertOk();
    $response->assertJsonStructure([
        'status',
        'checked_at',
        'outbox' => ['pending_count', 'oldest_pending_age_seconds'],
        'devices' => ['total_count', 'stale_count', 'newest_checkpoint_age_seconds'],
        'offline_sync' => ['for_review_count'],
    ]);

    // A fresh organization with zero devices ever registered must report
    // null (no data yet), never a misleading 0 that looks identical to
    // "confirmed zero devices are stale" — the exact distinction
    // docs/runbook.md's own Health/monitoring section requires.
    $response->assertJson([
        'devices' => ['total_count' => 0, 'stale_count' => null],
    ]);
});
