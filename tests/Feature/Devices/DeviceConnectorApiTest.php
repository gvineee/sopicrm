<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceConnectorNonce;
use App\Domain\Devices\Models\DeviceSyncCommand;
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

test('connector polls and acknowledges queued commands through scoped abilities', function () {
    $command = DeviceSyncCommand::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'status' => 'pending',
        'attempts' => 0,
    ]);
    $token = $this->organization->createToken('connector', [
        'device-connector:commands.read',
        'device-connector:commands.write',
    ])->plainTextToken;

    $this->withHeaders(connectorHeaders($token, 'nonce-00000000000006', 'commands-poll-1'))
        ->getJson(route('api.device-connector.commands.index', $this->device->id))
        ->assertOk()
        ->assertJsonPath('commands.0.id', $command->id)
        ->assertJsonPath('commands.0.commandVersion', $command->command_version)
        ->assertJsonPath('checkpoint.streamEpoch', 0)
        ->assertJsonPath('checkpoint.lastNativeEventId', 0);

    $this->withHeaders(connectorHeaders($token, 'nonce-00000000000007', "ack-{$command->id}"))
        ->postJson(route('api.device-connector.commands.acknowledge', [$this->device->id, $command->id]), [
            'result' => 'succeeded',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'succeeded');

    CurrentOrganization::set($this->organization->id);
    expect($command->refresh()->acknowledged_at)->not->toBeNull()
        ->and($this->device->refresh()->sync_status)->toBe('in_sync');
});

test('BIO-01: a real BioStar device withholds pending write commands from the connector poll by default', function () {
    config(['devices.adapter' => 'suprema', 'devices.biostar_write_dispatch_enabled' => false]);

    $command = DeviceSyncCommand::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'status' => 'pending',
        'attempts' => 0,
    ]);
    $token = $this->organization->createToken('connector', ['device-connector:commands.read'])->plainTextToken;

    $this->withHeaders(connectorHeaders($token, 'nonce-00000000000008', 'commands-poll-biostar-1'))
        ->getJson(route('api.device-connector.commands.index', $this->device->id))
        ->assertOk()
        ->assertJsonPath('commands', []);

    // Withheld, not lost: the command's own history/status is untouched —
    // it simply was never handed to a real adapter to execute.
    CurrentOrganization::set($this->organization->id);
    expect($command->refresh())
        ->status->toBe('pending')
        ->acknowledged_at->toBeNull();
});

test('BIO-01: enabling write dispatch explicitly lets a real BioStar device receive its queued commands again', function () {
    config(['devices.adapter' => 'suprema', 'devices.biostar_write_dispatch_enabled' => true]);

    $command = DeviceSyncCommand::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'status' => 'pending',
        'attempts' => 0,
    ]);
    $token = $this->organization->createToken('connector', ['device-connector:commands.read'])->plainTextToken;

    $this->withHeaders(connectorHeaders($token, 'nonce-00000000000009', 'commands-poll-biostar-2'))
        ->getJson(route('api.device-connector.commands.index', $this->device->id))
        ->assertOk()
        ->assertJsonPath('commands.0.id', $command->id);
});

test('BIO-01: simulator-mode devices are unaffected by the BioStar read-only gate', function () {
    config(['devices.adapter' => 'simulator', 'devices.biostar_write_dispatch_enabled' => false]);

    $command = DeviceSyncCommand::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'status' => 'pending',
        'attempts' => 0,
    ]);
    $token = $this->organization->createToken('connector', ['device-connector:commands.read'])->plainTextToken;

    $this->withHeaders(connectorHeaders($token, 'nonce-00000000000010', 'commands-poll-simulator-1'))
        ->getJson(route('api.device-connector.commands.index', $this->device->id))
        ->assertOk()
        ->assertJsonPath('commands.0.id', $command->id);
});
