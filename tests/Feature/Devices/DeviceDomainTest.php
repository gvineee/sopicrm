<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Actions\IssueCredentialAction;
use App\Domain\Devices\Actions\ProcessDeviceSyncCommandAction;
use App\Domain\Devices\Actions\RegisterDeviceAction;
use App\Domain\Devices\Actions\RevokeCredentialAction;
use App\Domain\Devices\Adapters\SimulatorDeviceAdapter;
use App\Domain\Devices\Contracts\DeviceAdapterInterface;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceSyncCommand;
use App\Domain\Devices\Models\Site;
use App\Domain\Devices\Services\CardIdentifierNormalizer;
use App\Domain\Devices\Services\DeviceDesiredStateResolver;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Services\CurrentOrganization;

pest()->group('devices');

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
});

test('simulator adapter is bound and capability snapshots are read on registration', function () {
    $site = Site::factory()->create(['organization_id' => $this->organization->id]);

    expect(app(DeviceAdapterInterface::class))->toBeInstanceOf(SimulatorDeviceAdapter::class);

    $device = app(RegisterDeviceAction::class)->execute([
        'site_id' => $site->id,
        'serial_number' => 'SIM-0001',
        'model' => 'XPASS2-XP2-MDPB',
        'reader_role' => 'in',
    ]);

    expect($device->capabilities()->where('capability_key', 'simulated')->exists())->toBeTrue()
        ->and(app(DeviceAdapterInterface::class)->label())->toContain('Simulator');
});

test('card normalization preserves leading zero bytes and arbitrary length', function () {
    $normalizer = app(CardIdentifierNormalizer::class);
    $fromHex = $normalizer->fromHex('00 FF 01', 'MIFARE', 24);
    $fromDecimal = $normalizer->fromDecimal($fromHex->canonicalIdentifier, 'MIFARE', 24);

    expect($fromHex->rawBytesHex)->toBe('00FF01')
        ->and($fromHex->bitLength)->toBe(24)
        ->and($fromHex->leadingZerosPreserved)->toBeTrue()
        ->and($fromDecimal->rawBytesHex)->toBe('00FF01');
});

test('offline revocation remains pending and is never shown as acknowledged', function () {
    $site = Site::factory()->create(['organization_id' => $this->organization->id]);
    $device = Device::factory()->create([
        'organization_id' => $this->organization->id,
        'site_id' => $site->id,
        'status' => 'offline',
    ]);
    $employee = Employee::factory()->create(['organization_id' => $this->organization->id]);
    $card = app(CardIdentifierNormalizer::class)->fromHex('00001234', 'EM', 32);

    $assignment = app(IssueCredentialAction::class)->execute(
        $card,
        $employee->id,
        siteIds: [$site->id],
    );

    $addCommand = DeviceSyncCommand::query()->sole();
    app(ProcessDeviceSyncCommandAction::class)->execute($addCommand);
    app(RevokeCredentialAction::class)->execute($assignment, 'Lost card');

    $revocation = DeviceSyncCommand::query()->where('command_type', 'revoke_credential')->sole();
    app(ProcessDeviceSyncCommandAction::class)->execute($revocation);
    $state = app(DeviceDesiredStateResolver::class)->stateFor($device, $assignment);

    expect($revocation->refresh()->status)->toBe('retry')
        ->and($state['desired'])->toBe('revoked')
        ->and($state['acknowledged'])->toBe('not_synced')
        ->and($state['is_pending'])->toBeTrue();
});
