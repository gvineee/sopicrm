<?php

namespace App\Domain\Devices\Actions;

use App\Domain\Attendance\Models\AttendanceAnomaly;
use App\Domain\Attendance\Models\RawAccessEvent;
use App\Domain\Devices\Models\Credential;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceCheckpoint;
use App\Domain\Devices\Models\ExternalIdentifierMapping;
use App\Domain\Devices\Services\CardIdentifierNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Append-only, overlap-safe device event ingestion. The durable dedup key is
 * (organization, device, native event id, stream epoch); payload hashes are
 * recorded only as a secondary diagnostic signal.
 */
class IngestRawAccessEventAction
{
    public function __construct(private readonly CardIdentifierNormalizer $normalizer) {}

    /**
     * @param  array{
     *     native_event_id: int,
     *     stream_epoch: int,
     *     raw_device_time: string,
     *     event_code: string,
     *     server_time?: string|null,
     *     event_subcode?: string|null,
     *     card_type?: string|null,
     *     card_hex?: string|null,
     *     bit_length?: int|null,
     *     payload?: array<string, mixed>,
     *     ingestion_source?: string,
     *     clock_offset_seconds?: int|null
     * } $eventData
     */
    public function execute(Device $device, array $eventData): RawAccessEvent
    {
        return DB::transaction(function () use ($device, $eventData): RawAccessEvent {
            $nativeEventId = $eventData['native_event_id'];
            $streamEpoch = $eventData['stream_epoch'];
            $rawDeviceTime = CarbonImmutable::parse($eventData['raw_device_time'])->utc();
            $payload = $eventData['payload'] ?? [];
            [$credential, $unmatchedReference] = $this->resolveCredential($eventData);

            if ($unmatchedReference !== null) {
                $this->registerUnmatchedCardForTriage($unmatchedReference);
            }

            $checkpoint = DeviceCheckpoint::query()
                ->where('device_id', $device->id)
                ->lockForUpdate()
                ->first();

            $existing = RawAccessEvent::query()
                ->where('device_id', $device->id)
                ->where('native_event_id', $nativeEventId)
                ->where('stream_epoch', $streamEpoch)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $event = RawAccessEvent::create([
                'device_id' => $device->id,
                'native_event_id' => $nativeEventId,
                'stream_epoch' => $streamEpoch,
                // The two columns mean different things and are finally used
                // that way: `raw_device_time` is what the device CLAIMED, and
                // `normalized_event_time_utc` is the time everything
                // downstream computes from. Where the upstream system reports
                // its own trustworthy UTC (BioStar's `server_datetime`), that
                // is the normalized one; the device's claim is kept beside it
                // rather than overwritten, so a drifting clock stays visible
                // instead of being quietly corrected away.
                'raw_device_time' => $rawDeviceTime,
                'normalized_event_time_utc' => $eventData['server_time'] ?? $rawDeviceTime,
                'received_at' => now(),
                'credential_id' => $credential?->id,
                'unmatched_credential_ref' => $unmatchedReference,
                'event_code' => $eventData['event_code'],
                'event_subcode' => $eventData['event_subcode'] ?? null,
                // Direction is configuration captured at ingestion time; a
                // client-supplied direction can never rewrite reader setup.
                'reader_direction_snapshot' => $device->reader_role,
                'payload' => $payload,
                'payload_hash' => hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR)),
                'ingestion_source' => $eventData['ingestion_source'] ?? 'device-connector',
            ]);

            $this->detectStreamAnomalies($device, $event, $checkpoint);
            $this->detectClockDrift($device, $event, $credential, $eventData['clock_offset_seconds'] ?? null);
            $this->advanceCheckpoint($device, $checkpoint, $streamEpoch, $nativeEventId);

            $device->update(['last_event_at' => now(), 'last_seen_at' => now()]);

            return $event;
        });
    }

    /**
     * @param  array<string, mixed>  $eventData
     * @return array{0: Credential|null, 1: string|null}
     */
    private function resolveCredential(array $eventData): array
    {
        $cardHex = $eventData['card_hex'] ?? null;
        $cardType = $eventData['card_type'] ?? null;

        if (! is_string($cardHex) || $cardHex === '' || ! is_string($cardType) || $cardType === '') {
            return [null, null];
        }

        $normalized = $this->normalizer->fromHex(
            $cardHex,
            $cardType,
            isset($eventData['bit_length']) ? (int) $eventData['bit_length'] : null,
        );

        $credential = Credential::query()
            ->where('card_type', $normalized->cardType)
            ->where('canonical_identifier', $normalized->canonicalIdentifier)
            ->first();

        return [$credential, $credential === null ? "{$normalized->cardType}:{$normalized->rawBytesHex}" : null];
    }

    /**
     * BIO-02: makes an unmatched card DISCOVERABLE on an admin triage page
     * instead of only visible by manually scanning `raw_access_events`. Does
     * NOT create an Employee or a Credential — this is a durable "we saw
     * this" marker only; `firstOrCreate` keeps a repeat swipe of the same
     * still-unrecognized card from spamming a new triage row every time.
     */
    private function registerUnmatchedCardForTriage(string $reference): void
    {
        ExternalIdentifierMapping::query()->firstOrCreate(
            [
                'source_system' => 'biostar',
                'source_instance_key' => 'default',
                'external_type' => 'card',
                'external_identifier' => $reference,
            ],
            ['status' => 'pending', 'first_seen_at' => now()],
        );
    }

    private function detectStreamAnomalies(
        Device $device,
        RawAccessEvent $event,
        ?DeviceCheckpoint $checkpoint,
    ): void {
        if ($checkpoint === null) {
            return;
        }

        if ($event->stream_epoch < $checkpoint->stream_epoch
            || ($event->stream_epoch === $checkpoint->stream_epoch
                && $event->native_event_id < $checkpoint->last_native_event_id)) {
            $this->createAnomaly($device, $event, 'out_of_order_events', [
                'checkpoint_stream_epoch' => $checkpoint->stream_epoch,
                'checkpoint_native_event_id' => $checkpoint->last_native_event_id,
                'received_stream_epoch' => $event->stream_epoch,
                'received_native_event_id' => $event->native_event_id,
            ]);
        }

        if ($event->stream_epoch === $checkpoint->stream_epoch
            && $checkpoint->last_native_event_id > 0
            && $event->native_event_id > $checkpoint->last_native_event_id + 1) {
            $this->createAnomaly($device, $event, 'data_gap', [
                'missing_from_native_event_id' => $checkpoint->last_native_event_id + 1,
                'missing_to_native_event_id' => $event->native_event_id - 1,
                'stream_epoch' => $event->stream_epoch,
            ]);
        }
    }

    private function detectClockDrift(
        Device $device,
        RawAccessEvent $event,
        ?Credential $credential,
        mixed $clockOffsetSeconds,
    ): void {
        if (! is_int($clockOffsetSeconds)
            || abs($clockOffsetSeconds) <= (int) config('devices.clock_drift_threshold_seconds', 300)) {
            return;
        }

        $employeeId = $credential?->assignments()
            ->activeAt($event->normalized_event_time_utc)
            ->value('employee_id');

        $this->createAnomaly($device, $event, 'clock_drift', [
            'clock_offset_seconds' => $clockOffsetSeconds,
            'threshold_seconds' => (int) config('devices.clock_drift_threshold_seconds', 300),
        ], is_string($employeeId) ? $employeeId : null);
    }

    private function advanceCheckpoint(
        Device $device,
        ?DeviceCheckpoint $checkpoint,
        int $streamEpoch,
        int $nativeEventId,
    ): void {
        if ($checkpoint === null) {
            DeviceCheckpoint::create([
                'device_id' => $device->id,
                'stream_epoch' => $streamEpoch,
                'last_native_event_id' => $nativeEventId,
                'last_confirmed_at' => now(),
            ]);

            return;
        }

        if ($streamEpoch > $checkpoint->stream_epoch
            || ($streamEpoch === $checkpoint->stream_epoch && $nativeEventId > $checkpoint->last_native_event_id)) {
            $checkpoint->update([
                'stream_epoch' => $streamEpoch,
                'last_native_event_id' => $nativeEventId,
                'last_confirmed_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function createAnomaly(
        Device $device,
        RawAccessEvent $event,
        string $type,
        array $details,
        ?string $employeeId = null,
    ): void {
        AttendanceAnomaly::query()->firstOrCreate(
            [
                'device_id' => $device->id,
                'raw_access_event_id' => $event->id,
                'anomaly_type' => $type,
            ],
            [
                'employee_id' => $employeeId,
                'detected_at' => now(),
                'details' => $details,
            ],
        );
    }
}
