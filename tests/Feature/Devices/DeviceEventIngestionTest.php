<?php

use App\Domain\Attendance\Models\AttendanceAnomaly;
use App\Domain\Attendance\Models\RawAccessEvent;
use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Actions\IngestRawAccessEventAction;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceCheckpoint;
use App\Domain\Devices\Models\Site;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Services\CurrentOrganization;
use LogicException;

pest()->group('devices');

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    $site = Site::factory()->create(['organization_id' => $this->organization->id]);
    $this->device = Device::factory()->create([
        'organization_id' => $this->organization->id,
        'site_id' => $site->id,
        'reader_role' => 'in',
    ]);
});

test('overlapping batches deduplicate by device native id and epoch while preserving unknown card triage', function () {
    $payload = [
        'native_event_id' => 41,
        'stream_epoch' => 2,
        'raw_device_time' => now()->toIso8601String(),
        'event_code' => 'access_denied',
        'card_type' => 'EM',
        'card_hex' => '00 00 12 34',
        'bit_length' => 32,
        'payload' => ['native' => ['code' => 7]],
    ];

    $first = app(IngestRawAccessEventAction::class)->execute($this->device, $payload);
    $second = app(IngestRawAccessEventAction::class)->execute($this->device, $payload);

    expect($second->id)->toBe($first->id)
        ->and(RawAccessEvent::query()->count())->toBe(1)
        ->and($first->unmatched_credential_ref)->toBe('EM:00001234')
        ->and($first->credential_id)->toBeNull()
        ->and(Employee::query()->count())->toBe(0)
        ->and($first->reader_direction_snapshot)->toBe('in');
});

test('checkpoint advances monotonically across gaps and epoch rollover and creates stream anomalies', function () {
    $action = app(IngestRawAccessEventAction::class);
    $base = [
        'stream_epoch' => 3,
        'raw_device_time' => now()->toIso8601String(),
        'event_code' => 'access_granted',
    ];

    $action->execute($this->device, $base + ['native_event_id' => 10]);
    $gapEvent = $action->execute($this->device, $base + ['native_event_id' => 13]);
    $oldEvent = $action->execute($this->device, $base + ['native_event_id' => 11]);
    $action->execute($this->device, array_merge($base, ['native_event_id' => 1, 'stream_epoch' => 4]));

    $checkpoint = DeviceCheckpoint::query()->where('device_id', $this->device->id)->sole();

    expect($checkpoint->stream_epoch)->toBe(4)
        ->and($checkpoint->last_native_event_id)->toBe(1)
        ->and(AttendanceAnomaly::query()->where('raw_access_event_id', $gapEvent->id)->where('anomaly_type', 'data_gap')->exists())->toBeTrue()
        ->and(AttendanceAnomaly::query()->where('raw_access_event_id', $oldEvent->id)->where('anomaly_type', 'out_of_order_events')->exists())->toBeTrue();
});

test('measured excessive clock offset creates a clock drift anomaly', function () {
    $event = app(IngestRawAccessEventAction::class)->execute($this->device, [
        'native_event_id' => 1,
        'stream_epoch' => 0,
        'raw_device_time' => now()->toIso8601String(),
        'event_code' => 'access_granted',
        'clock_offset_seconds' => 601,
    ]);

    expect(AttendanceAnomaly::query()
        ->where('raw_access_event_id', $event->id)
        ->where('anomaly_type', 'clock_drift')
        ->exists())->toBeTrue();
});

test('raw access events reject updates and deletes', function () {
    $event = app(IngestRawAccessEventAction::class)->execute($this->device, [
        'native_event_id' => 1,
        'stream_epoch' => 0,
        'raw_device_time' => now()->toIso8601String(),
        'event_code' => 'access_granted',
    ]);

    expect(fn () => $event->update(['event_code' => 'changed']))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);
});
