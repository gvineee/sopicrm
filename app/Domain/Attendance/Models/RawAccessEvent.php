<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Devices\Models\Credential;
use App\Domain\Devices\Models\Device;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use Carbon\CarbonInterface;
use Database\Factories\RawAccessEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * docs/data-model.md "raw_access_events" — immutable, append-only (spec
 * section 6). Deliberately has NO `version` (no `HasVersion`) and no
 * `updated_at` (`UPDATED_AT = null`): there is no code path that ever
 * updates a row here — corrections happen only at the AttendanceAdjustment
 * layer. Dedup is `(organization_id, device_id, native_event_id,
 * stream_epoch)`; `payload_hash` is an auxiliary secondary check only, never
 * the dedup key itself.
 *
 * @property CarbonInterface $normalized_event_time_utc
 * @property CarbonInterface $raw_device_time
 * @property CarbonInterface $received_at
 */
class RawAccessEvent extends Model
{
    /** @use HasFactory<RawAccessEventFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'device_id',
        'native_event_id',
        'stream_epoch',
        'raw_device_time',
        'normalized_event_time_utc',
        'received_at',
        'credential_id',
        'unmatched_credential_ref',
        'event_code',
        'event_subcode',
        'reader_direction_snapshot',
        'payload',
        'payload_hash',
        'ingestion_source',
    ];

    protected function casts(): array
    {
        return [
            'raw_device_time' => 'datetime',
            'normalized_event_time_utc' => 'datetime',
            'received_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Raw access events are immutable.'));
        static::deleting(fn () => throw new LogicException('Raw access events are append-only.'));
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return BelongsTo<Credential, $this>
     */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    protected static function newFactory(): RawAccessEventFactory
    {
        return RawAccessEventFactory::new();
    }
}
