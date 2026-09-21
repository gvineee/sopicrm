<?php

use App\Domain\Attendance\Models\AttendanceAnomaly;
use App\Domain\Attendance\Models\RawAccessEvent;
use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Models\Credential;
use App\Domain\Devices\Models\CredentialAssignment;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceCheckpoint;
use App\Domain\Devices\Models\DeviceSyncCommand;
use App\Domain\Devices\Models\Site;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

pest()->group('devices');

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
    $this->site = Site::factory()->create(['organization_id' => $this->organization->id]);
});

test('authorized operator can create view edit and update a device', function () {
    $this->actingAs($this->owner)
        ->get(route('devices.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Devices/Index')->where('isSimulatorMode', true));

    $this->actingAs($this->owner)->post(route('devices.store'), [
        'site_id' => $this->site->id,
        'serial_number' => 'SIM-WEB-001',
        'model' => 'XPASS2',
        'reader_role' => 'in',
        'device_timezone' => 'Asia/Tbilisi',
    ])->assertSessionHasNoErrors()->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $device = Device::query()->where('serial_number', 'SIM-WEB-001')->sole();

    $this->actingAs($this->owner)
        ->get(route('devices.show', $device))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Devices/Show')
            ->where('device.serial_number', 'SIM-WEB-001')
            ->where('canEdit', true));

    $this->actingAs($this->owner)
        ->get(route('devices.edit', $device))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Devices/Edit'));

    $this->actingAs($this->owner)->patch(route('devices.update', $device), [
        'site_id' => $this->site->id,
        'serial_number' => 'SIM-WEB-001',
        'model' => 'XPASS2-V2',
        'firmware_version' => '2.1.0',
        'install_location' => 'Main entrance',
        'reader_role' => 'out',
        'device_timezone' => 'Asia/Tbilisi',
    ])->assertRedirect(route('devices.show', $device));

    CurrentOrganization::set($this->organization->id);
    expect($device->refresh()->model)->toBe('XPASS2-V2')
        ->and($device->reader_role)->toBe('out')
        ->and($device->sync_status)->toBe('pending');
});

test('device registry accepts production connection and identity fields', function () {
    $this->actingAs($this->owner)->post(route('devices.store'), [
        'name' => 'Main Gate XPass',
        'vendor' => 'suprema',
        'site_id' => $this->site->id,
        'serial_number' => 'XP2-PROD-001',
        'device_identifier' => 'gateway-main-gate-001',
        'ip_address' => '192.168.10.201',
        'port' => 51211,
        'mac_address' => 'AA:BB:CC:DD:EE:FF',
        'model' => 'XP2-MDPB',
        'hardware_version' => '1.2',
        'connection_mode' => 'gateway',
        'reader_role' => 'in',
        'device_timezone' => 'Asia/Tbilisi',
        'enabled' => true,
    ])->assertSessionHasNoErrors()->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $device = Device::query()->where('serial_number', 'XP2-PROD-001')->sole();
    expect($device->name)->toBe('Main Gate XPass')
        ->and($device->device_identifier)->toBe('gateway-main-gate-001')
        ->and($device->ip_address)->toBe('192.168.10.201')
        ->and($device->port)->toBe(51211)
        ->and($device->enabled)->toBeTrue();
});

test('user without device permissions is forbidden from device pages', function () {
    $user = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $device = Device::factory()->create([
        'organization_id' => $this->organization->id,
        'site_id' => $this->site->id,
    ]);

    $this->actingAs($user)->get(route('devices.index'))->assertForbidden();
    $this->actingAs($user)->get(route('devices.show', $device))->assertForbidden();
});

test('credential UI shows desired state separately from unacknowledged state', function () {
    $device = Device::factory()->create([
        'organization_id' => $this->organization->id,
        'site_id' => $this->site->id,
        'status' => 'offline',
    ]);
    $employee = Employee::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($this->owner)->post(route('credentials.store'), [
        'card_type' => 'EM',
        'input_format' => 'hex',
        'card_value' => '00 00 AB CD',
        'bit_length' => 32,
        'employee_id' => $employee->id,
        'site_ids' => [$this->site->id],
    ])->assertRedirect(route('credentials.index'));

    CurrentOrganization::set($this->organization->id);
    $assignment = CredentialAssignment::query()->sole();
    $this->actingAs($this->owner)->post(route('credentials.revoke', $assignment), [
        'reason' => 'Lost during simulator test',
    ])->assertRedirect(route('credentials.index'));

    $this->actingAs($this->owner)
        ->get(route('credentials.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Devices/Credentials/Index')
            ->where('isSimulatorMode', true)
            ->where('credentials.data.0.status', 'revoked')
            ->where('credentials.data.0.device_sync.0.device_id', $device->id)
            ->where('credentials.data.0.device_sync.0.desired', 'revoked')
            ->where('credentials.data.0.device_sync.0.acknowledged', 'not_synced')
            ->where('credentials.data.0.device_sync.0.is_pending', true));

    CurrentOrganization::set($this->organization->id);
    expect(Credential::query()->sole()->canonical_identifier)->toBe('43981');
});

test('simulator controls create an immutable event without creating an employee for an unknown card', function () {
    $device = Device::factory()->create([
        'organization_id' => $this->organization->id,
        'site_id' => $this->site->id,
        'reader_role' => 'in',
    ]);
    $employeeCount = Employee::query()->count();

    $this->actingAs($this->owner)->post(route('devices.simulator.status', $device), [
        'status' => 'online',
    ])->assertRedirect();

    $this->actingAs($this->owner)->post(route('devices.simulator.generate-event', $device), [
        'native_event_id' => 1,
        'stream_epoch' => 1,
        'card_type' => 'EM',
        'card_hex' => '00FF01',
        'direction' => 'in',
        'event_code' => 'access_denied',
    ])->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $event = RawAccessEvent::query()->sole();
    expect($event->unmatched_credential_ref)->toBe('EM:00FF01')
        ->and($event->reader_direction_snapshot)->toBe('in')
        ->and(Employee::query()->count())->toBe($employeeCount);
});

test('BIO-03: the device page shows real import health — checkpoint, open anomalies and command backlog', function () {
    $device = Device::factory()->create([
        'organization_id' => $this->organization->id,
        'site_id' => $this->site->id,
    ]);
    DeviceCheckpoint::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $device->id,
        'stream_epoch' => 3,
        'last_native_event_id' => 42,
        'last_confirmed_at' => now(),
    ]);
    AttendanceAnomaly::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $device->id,
        'employee_id' => null,
        'anomaly_type' => 'data_gap',
        'resolved_at' => null,
    ]);
    // A resolved anomaly must not count toward the open total.
    AttendanceAnomaly::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $device->id,
        'employee_id' => null,
        'anomaly_type' => 'data_gap',
        'resolved_at' => now(),
    ]);
    DeviceSyncCommand::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $device->id,
        'status' => 'dead_letter',
    ]);

    $this->actingAs($this->owner)
        ->get(route('devices.show', $device))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Devices/Show')
            ->where('importHealth.checkpoint.stream_epoch', 3)
            ->where('importHealth.checkpoint.last_native_event_id', 42)
            ->where('importHealth.open_anomalies.data_gap', 1)
            ->where('importHealth.command_backlog.dead_letter', 1));
});
