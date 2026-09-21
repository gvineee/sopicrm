<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Actions\ProcessDeviceSyncCommandAction;
use App\Domain\Devices\Actions\ReconcileDeviceStateAction;
use App\Domain\Devices\Models\Credential;
use App\Domain\Devices\Models\CredentialAssignment;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceSyncCommand;
use App\Domain\Devices\Models\Site;
use App\Domain\Devices\Services\DeviceSyncStatusResolver;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Services\CurrentOrganization;
use Illuminate\Database\QueryException;

pest()->group('devices');

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    $site = Site::factory()->create(['organization_id' => $this->organization->id]);
    $this->device = Device::factory()->create([
        'organization_id' => $this->organization->id,
        'site_id' => $site->id,
        'status' => 'online',
    ]);
});

test('sync status is derived from the latest command per target', function () {
    $targetId = fake()->uuid();
    DeviceSyncCommand::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'target_entity_type' => CredentialAssignment::class,
        'target_entity_id' => $targetId,
        'command_version' => 1,
        'status' => 'failed',
    ]);
    DeviceSyncCommand::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'target_entity_type' => CredentialAssignment::class,
        'target_entity_id' => $targetId,
        'command_version' => 2,
        'status' => 'succeeded',
    ]);

    $resolver = app(DeviceSyncStatusResolver::class);
    expect($resolver->statusFor($this->device))->toBe('in_sync');

    DeviceSyncCommand::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'target_entity_type' => CredentialAssignment::class,
        'target_entity_id' => fake()->uuid(),
        'command_version' => 1,
        'status' => 'pending',
    ]);
    expect($resolver->statusFor($this->device))->toBe('pending');
});

test('retry reaches dead letter at the configured limit and device sync becomes error', function () {
    config()->set('devices.sync_command_max_attempts', 2);
    $this->device->update(['status' => 'degraded']);
    $command = DeviceSyncCommand::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'target_entity_type' => CredentialAssignment::class,
        'target_entity_id' => fake()->uuid(),
        'command_version' => 3,
        'status' => 'pending',
    ]);

    $action = app(ProcessDeviceSyncCommandAction::class);
    expect($action->execute($command)->status)->toBe('retry')
        ->and($this->device->refresh()->sync_status)->toBe('pending');

    expect($action->execute($command->refresh())->status)->toBe('dead_letter')
        ->and($this->device->refresh()->sync_status)->toBe('error');
});

test('a stale retry is superseded without overwriting a newer success', function () {
    $targetId = fake()->uuid();
    $old = DeviceSyncCommand::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'target_entity_type' => CredentialAssignment::class,
        'target_entity_id' => $targetId,
        'command_version' => 1,
        'status' => 'retry',
    ]);
    DeviceSyncCommand::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'target_entity_type' => CredentialAssignment::class,
        'target_entity_id' => $targetId,
        'command_version' => 2,
        'status' => 'succeeded',
    ]);

    $result = app(ProcessDeviceSyncCommandAction::class)->execute($old);

    expect($result->status)->toBe('failed')
        ->and($result->last_error)->toContain('superseded')
        ->and($this->device->refresh()->sync_status)->toBe('in_sync');
});

test('reconciliation enqueues only the divergent target and leaves status pending', function () {
    $credential = Credential::factory()->create(['organization_id' => $this->organization->id]);
    $employee = Employee::factory()->create(['organization_id' => $this->organization->id]);
    $assignment = CredentialAssignment::factory()->create([
        'organization_id' => $this->organization->id,
        'credential_id' => $credential->id,
        'employee_id' => $employee->id,
    ]);
    DeviceSyncCommand::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'target_entity_type' => $assignment->getMorphClass(),
        'target_entity_id' => $assignment->id,
        'command_version' => 1,
        'status' => 'succeeded',
    ]);
    DeviceSyncCommand::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'target_entity_type' => $assignment->getMorphClass(),
        'target_entity_id' => $assignment->id,
        'command_version' => 2,
        'status' => 'failed',
        'payload' => ['employee_id' => $employee->id],
    ]);

    $summary = app(ReconcileDeviceStateAction::class)->execute($this->device);

    expect($summary)->toBe(['checked' => 1, 'corrected' => 1])
        ->and(DeviceSyncCommand::query()->where('device_id', $this->device->id)->count())->toBe(3)
        ->and(DeviceSyncCommand::query()->latest('command_version')->firstOrFail()->command_version)->toBe(3)
        ->and($this->device->refresh()->sync_status)->toBe('pending');
});

test('credential ownership is resolved by historical validity window and active assignment is unique', function () {
    $credential = Credential::factory()->create(['organization_id' => $this->organization->id]);
    $firstEmployee = Employee::factory()->create(['organization_id' => $this->organization->id]);
    $secondEmployee = Employee::factory()->create(['organization_id' => $this->organization->id]);
    CredentialAssignment::factory()->create([
        'organization_id' => $this->organization->id,
        'credential_id' => $credential->id,
        'employee_id' => $firstEmployee->id,
        'valid_from' => '2026-01-01 00:00:00',
        'valid_to' => '2026-02-01 00:00:00',
        'status' => 'superseded',
    ]);
    CredentialAssignment::factory()->create([
        'organization_id' => $this->organization->id,
        'credential_id' => $credential->id,
        'employee_id' => $secondEmployee->id,
        'valid_from' => '2026-02-01 00:00:01',
        'status' => 'active',
    ]);

    expect($credential->assignments()->activeAt(now()->setDate(2026, 1, 15))->sole()->employee_id)->toBe($firstEmployee->id)
        ->and($credential->assignments()->activeAt(now()->setDate(2026, 2, 15))->sole()->employee_id)->toBe($secondEmployee->id)
        ->and(fn () => CredentialAssignment::factory()->create([
            'organization_id' => $this->organization->id,
            'credential_id' => $credential->id,
            'employee_id' => $firstEmployee->id,
            'status' => 'active',
        ]))->toThrow(QueryException::class);
});
