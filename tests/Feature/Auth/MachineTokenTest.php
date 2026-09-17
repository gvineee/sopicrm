<?php

use App\Domain\Auth\Models\Organization;
use Illuminate\Support\Str;

/**
 * spec sections 20/21: machine/API clients (services/device-connector, a
 * future server-to-server integration) authenticate with a scoped Sanctum
 * token bound to exactly one organization — never a shared/generic API
 * key, and never trusting a client-supplied organization id.
 */
test('a machine token issued via the console command authenticates as its bound organization', function () {
    $organization = Organization::factory()->create();
    $other = Organization::factory()->create();

    $this->artisan('tokens:issue-machine', [
        'organization' => $organization->id,
        'name' => 'device-connector-test',
        '--ability' => ['device-connector:events.write'],
    ])->assertSuccessful();

    $token = $organization->tokens()->sole();

    expect($token->organization_id)->toBe($organization->id)
        ->and($token->organization_id)->not->toBe($other->id)
        ->and($token->can('device-connector:events.write'))->toBeTrue()
        ->and($token->can('something-else'))->toBeFalse();
});

test("a request authenticated with a machine token resolves /api/v1/me to that token's organization, never a client-supplied one", function () {
    $organization = Organization::factory()->create();

    $result = $organization->createToken('device-connector-test');
    $result->accessToken->forceFill(['organization_id' => $organization->id])->save();

    $response = $this->withHeader('Authorization', 'Bearer '.$result->plainTextToken)
        // A client-supplied organization id anywhere in the request must be
        // ignored — the middleware derives it only from the token itself.
        ->getJson('/api/v1/me?organization_id='.Str::uuid7());

    $response->assertOk()->assertJson([
        'type' => 'machine',
        'organizationId' => $organization->id,
    ]);
});
