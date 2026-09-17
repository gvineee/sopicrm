<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceConnectorNonce;
use App\Domain\Devices\Models\Site;
use App\Domain\Shared\Models\IdempotencyRecord;
use App\Domain\Shared\Services\CurrentOrganization;

pest()->group('devices');

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    $site = Site::factory()->create(['organization_id' => $this->organization->id]);
    $this->device = Device::factory()->create([
        'organization_id' => $this->organization->id,
        'site_id' => $site->id,
    ]);
});

function connectorHeaders(string $token, string $nonce, string $idempotencyKey): array
{
    return [
        'Authorization' => "Bearer {$token}",
        'Accept' => 'application/json',
        'X-Connector-Timestamp' => (string) now()->timestamp,
        'X-Connector-Nonce' => $nonce,
        'Idempotency-Key' => $idempotencyKey,
    ];
}

test('machine event ingestion enforces ability replay protection and idempotent retry', function () {
    $token = $this->organization->createToken('connector', ['device-connector:events.write'])->plainTextToken;
    $url = route('api.device-connector.events.store', $this->device->id);
    $body = ['events' => [[
        'native_event_id' => 5,
        'stream_epoch' => 0,
        'raw_device_time' => now()->toIso8601String(),
        'event_code' => 'access_granted',
    ]]];

    $this->withHeaders(connectorHeaders($token, 'nonce-00000000000001', 'batch-5'))
        ->postJson($url, $body)
        ->assertAccepted();

    $this->withHeaders(connectorHeaders($token, 'nonce-00000000000002', 'batch-5'))
        ->postJson($url, $body)
        ->assertAccepted()
        ->assertHeader('Idempotency-Replayed', 'true');

    CurrentOrganization::set($this->organization->id);
    expect(DeviceConnectorNonce::query()->count())->toBe(2)
        ->and(IdempotencyRecord::query()->sole()->user_id)->toBeNull();
});

test('a reused nonce is rejected before the connector request is processed', function () {
    $token = $this->organization->createToken('connector', ['device-connector:heartbeat.write'])->plainTextToken;
    $url = route('api.device-connector.heartbeat.store', $this->device->id);
    $headers = connectorHeaders($token, 'nonce-00000000000003', 'heartbeat-1');
    $body = ['status' => 'online', 'connector_version' => '1.0.0'];

    $this->withHeaders($headers)->postJson($url, $body)->assertOk();
    $this->withHeaders($headers)->postJson($url, $body)
        ->assertConflict()
        ->assertJsonPath('code', 'connector_request_replayed');
});

test('a machine token without the endpoint ability is forbidden', function () {
    $token = $this->organization->createToken('connector', ['device-connector:commands.read'])->plainTextToken;

    $this->withHeaders(connectorHeaders($token, 'nonce-00000000000004', 'forbidden-1'))
        ->postJson(route('api.device-connector.events.store', $this->device->id), ['events' => []])
        ->assertForbidden()
        ->assertJsonPath('code', 'connector_ability_denied');
});

test('machine token cannot address another organizations device', function () {
    $otherOrganization = Organization::factory()->create();
    CurrentOrganization::set($otherOrganization->id);
    $otherSite = Site::factory()->create(['organization_id' => $otherOrganization->id]);
    $otherDevice = Device::factory()->create([
        'organization_id' => $otherOrganization->id,
        'site_id' => $otherSite->id,
    ]);
    $token = $this->organization->createToken('connector', ['device-connector:commands.read'])->plainTextToken;

    $this->withHeaders(connectorHeaders($token, 'nonce-00000000000005', 'cross-tenant-1'))
        ->getJson(route('api.device-connector.commands.index', $otherDevice->id))
        ->assertNotFound();
});
