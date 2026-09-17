<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Shared\Models\IdempotencyRecord;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;

/**
 * DEC-015 / spec section 20: "იგივე გასაღები განსხვავებული payload-ით
 * იწვევს conflict-ს." A throwaway route is registered just for this test
 * so the middleware is exercised through the real HTTP kernel (headers,
 * JSON response shape) rather than unit-testing the middleware class in
 * isolation.
 */
beforeEach(function () {
    Route::middleware(['web', 'idempotency'])->post('/__test/idempotent-charge', function (Request $request) {
        return response()->json(['charged' => $request->input('amount')]);
    });

    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->user = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
});

test('same idempotency key and same payload replays the cached response', function () {
    $this->actingAs($this->user);

    $first = $this->postJson('/__test/idempotent-charge', ['amount' => 100], [
        'Idempotency-Key' => 'key-1',
    ]);
    $first->assertOk()->assertJson(['charged' => 100]);
    expect($first->headers->get('Idempotency-Replayed'))->toBeNull();

    $second = $this->postJson('/__test/idempotent-charge', ['amount' => 100], [
        'Idempotency-Key' => 'key-1',
    ]);
    $second->assertOk()->assertJson(['charged' => 100]);
    expect($second->headers->get('Idempotency-Replayed'))->toBe('true');

    // App\Http\Middleware\SetCurrentOrganization clears the tenant context
    // again once the request finishes, so re-establish it here purely to
    // make this assertion (which runs outside any request) tenant-aware.
    CurrentOrganization::set($this->organization->id);
    expect(IdempotencyRecord::count())->toBe(1);
});

test('same idempotency key with a different payload is a 409 conflict', function () {
    $this->actingAs($this->user);

    $this->postJson('/__test/idempotent-charge', ['amount' => 100], [
        'Idempotency-Key' => 'key-2',
    ])->assertOk();

    $conflict = $this->postJson('/__test/idempotent-charge', ['amount' => 999], [
        'Idempotency-Key' => 'key-2',
    ]);

    $conflict->assertStatus(409)
        ->assertJson(['code' => 'idempotency_key_conflict']);

    CurrentOrganization::set($this->organization->id);
    expect(IdempotencyRecord::count())->toBe(1);
});

test('a missing idempotency key is rejected', function () {
    $this->actingAs($this->user);

    $this->postJson('/__test/idempotent-charge', ['amount' => 50])
        ->assertStatus(400)
        ->assertJson(['code' => 'idempotency_key_required']);
});

test('the same idempotency key is independent per organization', function () {
    $this->actingAs($this->user);

    $this->postJson('/__test/idempotent-charge', ['amount' => 100], [
        'Idempotency-Key' => 'shared-key',
    ])->assertOk();

    $otherOrganization = Organization::factory()->create();
    $otherUser = User::factory()->create([
        'organization_id' => $otherOrganization->id,
        'current_organization_id' => $otherOrganization->id,
    ]);

    CurrentOrganization::set($otherOrganization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($otherOrganization->id);
    $this->actingAs($otherUser);

    // Different organization, same key, DIFFERENT payload — must NOT
    // conflict, because idempotency records are keyed per-organization.
    $this->postJson('/__test/idempotent-charge', ['amount' => 777], [
        'Idempotency-Key' => 'shared-key',
    ])->assertOk()->assertJson(['charged' => 777]);
});
