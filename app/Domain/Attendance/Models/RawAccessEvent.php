<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Devices\Models\Credential;
use App\Domain\Devices\Models\Device;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use Carbon\CarbonInterface;
use Database\Factories\RawAccessEventFactory;
use Illuminate\Database\Eloquent\Builder;
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
 * The `payload` property is declared below because the cast turns that json
 * column into an array; without the declaration the inferred type stays the
 * raw column type and reading a key from it looks like indexing a string.
 *
 * @property array<string, mixed> $payload
 * @property string $ingestion_source
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

    /**
     * Provenance of an ingested event, as recorded at ingestion time and
     * validated by App\Http\Requests\Devices\StoreConnectorEventsRequest.
     * `simulator` means a human pressed "generate test event" (or the Node
     * connector ran in simulator mode); it never describes a real badge read.
     */
    public const SOURCE_DEVICE_CONNECTOR = 'device-connector';

    public const SOURCE_SIMULATOR = 'simulator';

    public const SOURCE_BIOSTAR_IMPORT = 'biostar-import';

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
     * Audit A04 / 01-CRM-Audit-KA.md: „Simulator-ის შედეგები არ უნდა
     * მონაწილეობდეს რეალურ ტაბელსა და ხელფასში." Anything that computes
     * worked time, a timesheet or money must read events through this scope.
     *
     * Two conditions, not one, because two different generations of rows
     * exist. New rows carry `ingestion_source = 'simulator'`. Rows written
     * before that was fixed carry the connector's default source and are
     * identifiable only by the marker the simulator has always put inside the
     * payload — and since this table is append-only by design, those rows can
     * never be re-tagged, so the payload check is the only honest way to
     * recognise them. Neither condition is a guess: both are facts recorded
     * at ingestion.
     *
     * @param  Builder<RawAccessEvent>  $query
     * @return Builder<RawAccessEvent>
     */
    public function scopeExcludingSimulated(Builder $query): Builder
    {
        return $query
            ->where('ingestion_source', '!=', self::SOURCE_SIMULATOR)
            ->where(function (Builder $inner): void {
                $inner->whereNull('payload->source')
                    ->orWhere('payload->source', '!=', self::SOURCE_SIMULATOR);
            });
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
