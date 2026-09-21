<?php

use App\Domain\Attendance\Models\RawAccessEvent;
use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Actions\IngestRawAccessEventAction;
use App\Domain\Devices\Actions\IssueCredentialAction;
use App\Domain\Devices\Actions\RevokeCredentialAction;
use App\Domain\Devices\Actions\RunDeviceConnectorTickAction;
use App\Domain\Devices\Adapters\SimulatorDeviceAdapter;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceSyncCommand;
use App\Domain\Devices\Models\Site;
use App\Domain\Devices\Services\CardIdentifierNormalizer;
use App\Domain\Devices\Services\DeviceDesiredStateResolver;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Services\CurrentOrganization;

pest()->group('devices');

test('simulator pilot covers issue event outage replay and confirmed revocation', function () {
    $organization = Organization::factory()->create();
    CurrentOrganization::set($organization->id);
    $site = Site::factory()->create(['organization_id' => $organization->id]);
    $device = Device::factory()->create([
        'organization_id' => $organization->id,
        'site_id' => $site->id,
        'reader_role' => 'in',
        'status' => 'online',
    ]);
    $employee = Employee::factory()->create(['organization_id' => $organization->id]);
    $card = app(CardIdentifierNormalizer::class)->fromHex('00123456', 'EM', 32);
    $assignment = app(IssueCredentialAction::class)->execute(
        $card,
        $employee->id,
        siteIds: [$site->id],
    );

    $firstTick = app(RunDeviceConnectorTickAction::class)->execute($device);
    expect($firstTick['succeeded'])->toBe(1)
        ->and(DeviceSyncCommand::query()->where('command_type', 'add_user')->sole()->status)->toBe('succeeded');

    $payload = app(SimulatorDeviceAdapter::class)->generateEventPayload(
        $device,
        nativeEventId: 100,
        streamEpoch: 7,
        cardHex: '00123456',
        cardType: 'EM',
        direction: 'in',
    );
    $firstEvent = app(IngestRawAccessEventAction::class)->execute($device, $payload);
    $replayedEvent = app(IngestRawAccessEventAction::class)->execute($device, $payload);

    expect($firstEvent->credential_id)->toBe($assignment->credential_id)
        ->and($replayedEvent->id)->toBe($firstEvent->id)
        ->and(RawAccessEvent::query()->count())->toBe(1);

    $device->update(['status' => 'offline']);
    app(RevokeCredentialAction::class)->execute($assignment, 'Pilot lost-card scenario');
    $offlineTick = app(RunDeviceConnectorTickAction::class)->execute($device->refresh());
    $pendingState = app(DeviceDesiredStateResolver::class)->stateFor($device->refresh(), $assignment->refresh());

    expect($offlineTick['pending'])->toBe(1)
        ->and($pendingState['desired'])->toBe('revoked')
        ->and($pendingState['acknowledged'])->toBe('granted')
        ->and($pendingState['is_pending'])->toBeTrue();

    $device->update(['status' => 'online']);
    $recoveryTick = app(RunDeviceConnectorTickAction::class)->execute($device->refresh());
    $confirmedState = app(DeviceDesiredStateResolver::class)->stateFor($device->refresh(), $assignment->refresh());

    expect($recoveryTick['succeeded'])->toBe(1)
        ->and($confirmedState['desired'])->toBe('revoked')
        ->and($confirmedState['acknowledged'])->toBe('revoked')
        ->and($confirmedState['is_pending'])->toBeFalse();
});
