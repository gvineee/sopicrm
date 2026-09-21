<?php

namespace App\Domain\Devices\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Carbon\CarbonInterface;
use Database\Factories\DeviceCheckpointFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/data-model.md "device_checkpoints" (spec section 6): resume point for
 * event ingestion after a network outage, overlapping-batch-safe via
 * `raw_access_events`' own dedup constraint.
 *
 * @property int $stream_epoch
 * @property int $last_native_event_id
 * @property CarbonInterface|null $last_confirmed_at
 */
class DeviceCheckpoint extends Model
{
    /** @use HasFactory<DeviceCheckpointFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'device_id',
        'stream_epoch',
        'last_native_event_id',
        'last_confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    protected static function newFactory(): DeviceCheckpointFactory
    {
        return DeviceCheckpointFactory::new();
    }
}
