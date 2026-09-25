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

test('a device with a wrong clock is reported once, not once per badge read', function () {
    $action = app(IngestRawAccessEventAction::class);

    // The first live import produced 388 identical clock-drift rows from a
    // single misconfigured reader — one real finding, restated once per
    // swipe, on a page somebody is meant to work through.
    foreach (range(1, 5) as $id) {
        $action->execute($this->device, [
            'native_event_id' => $id,
            'stream_epoch' => 0,
            'raw_device_time' => now()->toIso8601String(),
            'event_code' => 'access_granted',
            'clock_offset_seconds' => 10_800 + $id,
        ]);
    }

    $anomaly = AttendanceAnomaly::query()
        ->where('device_id', $this->device->id)
        ->where('anomaly_type', 'clock_drift')
        ->sole();

    // Still current: the one open row carries the latest measurement rather
    // than freezing on whatever the first swipe of the day happened to be.
    expect($anomaly->details['clock_offset_seconds'])->toBe(10_805);
});

test('a clock that drifts again after being resolved is reported again', function () {
    $action = app(IngestRawAccessEventAction::class);
    $base = [
        'stream_epoch' => 0,
        'raw_device_time' => now()->toIso8601String(),
        'event_code' => 'access_granted',
        'clock_offset_seconds' => 601,
    ];

    $action->execute($this->device, $base + ['native_event_id' => 1]);

    AttendanceAnomaly::query()->where('anomaly_type', 'clock_drift')->update(['resolved_at' => now()]);

    $action->execute($this->device, $base + ['native_event_id' => 2]);

    // Suppression is tied to an OPEN anomaly, so closing one does not make
    // the device permanently unable to report the same fault twice.
    expect(AttendanceAnomaly::query()->where('anomaly_type', 'clock_drift')->count())->toBe(2);
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
