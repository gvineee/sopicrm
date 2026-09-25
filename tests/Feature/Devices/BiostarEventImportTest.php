<?php

use App\Domain\Attendance\Models\RawAccessEvent;
use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Actions\IngestRawAccessEventAction;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\Site;
use App\Domain\Devices\Support\BiostarEventTaxonomy;
use App\Domain\Shared\Services\CurrentOrganization;

pest()->group('devices', 'attendance');

/**
 * The BioStar read path, with the two defects a live probe of the real server
 * exposed.
 *
 * 1. The connector sent BioStar's `datetime` as the event time. On the live
 *    install that reader's clock runs three hours behind real UTC while the
 *    server's `server_datetime` matches it exactly — so every worked hour
 *    computed from those events would have been wrong by three hours.
 * 2. Event codes were forwarded unmapped as `biostar:4102`. The attendance
 *    rebuild excludes exactly one code, `access_denied`, so an unmapped
 *    `biostar:6401 ACCESS_DENIED_ACCESS_GROUP` — a badge the door REFUSED —
 *    sailed past that exclusion. Someone turned away at the gate could have
 *    been counted as having arrived.
 */
beforeEach(function () {
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);

    $this->site = Site::factory()->create(['organization_id' => $this->organization->id]);
    $this->device = Device::factory()->create([
        'organization_id' => $this->organization->id,
        'site_id' => $this->site->id,
    ]);

    $this->ingest = fn (array $overrides = []) => app(IngestRawAccessEventAction::class)->execute(
        $this->device,
        array_merge([
            'native_event_id' => 1,
            'stream_epoch' => 0,
            'raw_device_time' => '2026-09-25T07:39:38.00Z',
            'event_code' => BiostarEventTaxonomy::GRANTED,
            'ingestion_source' => RawAccessEvent::SOURCE_BIOSTAR_IMPORT,
        ], $overrides),
    );
});

test('a granted card read is classified as such, and a refused one is not', function () {
    // These are the real codes this install reports.
    expect(BiostarEventTaxonomy::classify(4102))->toBe(BiostarEventTaxonomy::GRANTED)   // VERIFY_SUCCESS_CARD
        ->and(BiostarEventTaxonomy::classify(4867))->toBe(BiostarEventTaxonomy::GRANTED) // IDENTIFY_SUCCESS_FACE
        ->and(BiostarEventTaxonomy::classify(4354))->toBe(BiostarEventTaxonomy::DENIED)  // VERIFY_FAIL_CARD
        ->and(BiostarEventTaxonomy::classify(6401))->toBe(BiostarEventTaxonomy::DENIED)  // ACCESS_DENIED_ACCESS_GROUP
        ->and(BiostarEventTaxonomy::classify(6403))->toBe(BiostarEventTaxonomy::DENIED); // ACCESS_DENIED_EXPIRED
});

test('a refused badge maps to the exact code the attendance rebuild excludes', function () {
    // This is the whole point: `ReconstructAttendanceSessionsAction` excludes
    // `access_denied` and nothing else, so the mapping has to produce that
    // literal string rather than something merely similar.
    expect(BiostarEventTaxonomy::eventCodeFor(6401))->toBe('access_denied')
        ->and(BiostarEventTaxonomy::eventCodeFor(4102))->toBe('access_granted');
});

test('an event that says nothing about a person keeps its BioStar number', function () {
    // Door lock/unlock, tamper, restarts, enrolment. They are still imported —
    // the raw log stays complete — but they must not look like an arrival.
    foreach ([20480, 20736, 12544, 8192, 4095] as $code) {
        expect(BiostarEventTaxonomy::classify($code))->toBe(BiostarEventTaxonomy::OTHER)
            ->and(BiostarEventTaxonomy::eventCodeFor($code))->toBe("biostar:{$code}");
    }
});

test('an unknown or missing code is never mistaken for a granted entry', function () {
    foreach ([null, '', 'nonsense', 0, 999999] as $code) {
        expect(BiostarEventTaxonomy::isAccessGranted($code))->toBeFalse();
    }
});

test('the server time becomes the attendance time, and the device claim is kept beside it', function () {
    // The exact pair the live server reports: a reader three hours behind.
    $event = ($this->ingest)([
        'raw_device_time' => '2026-09-25T07:39:38.00Z',
        'server_time' => '2026-09-25T10:39:43.00Z',
    ]);

    expect($event->normalized_event_time_utc->toIso8601String())->toStartWith('2026-09-25T10:39:43')
        // Not overwritten: a drifting clock has to stay visible as a fact
        // rather than be quietly corrected away.
        ->and($event->raw_device_time->toIso8601String())->toStartWith('2026-09-25T07:39:38');
});

test('without an upstream server time, the device claim is still used', function () {
    // A connector that has no better source must not end up with a null time.
    $event = ($this->ingest)(['raw_device_time' => '2026-09-25T09:00:00.00Z']);

    expect($event->normalized_event_time_utc->toIso8601String())->toStartWith('2026-09-25T09:00:00');
});

test('a three-hour-wrong reader clock does not move the worked day', function () {
    // The failure this prevents, stated as the thing a person would notice:
    // an arrival recorded at 10:39 UTC must not be filed as 07:39.
    $morning = ($this->ingest)([
        'native_event_id' => 10,
        'raw_device_time' => '2026-09-25T05:00:00.00Z',
        'server_time' => '2026-09-25T08:00:00.00Z',
    ]);
    $evening = ($this->ingest)([
        'native_event_id' => 11,
        'raw_device_time' => '2026-09-25T14:00:00.00Z',
        'server_time' => '2026-09-25T17:00:00.00Z',
    ]);

    $workedHours = $evening->normalized_event_time_utc->diffInHours($morning->normalized_event_time_utc);

    // The span is the same either way — what changes is WHICH hours of the
    // day the work is attributed to, which is what a shift, a night premium
    // and an overtime rule all key off.
    expect(abs($workedHours))->toEqual(9)
        ->and($morning->normalized_event_time_utc->hour)->toBe(8)
        ->and($evening->normalized_event_time_utc->hour)->toBe(17);
});
