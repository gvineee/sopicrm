<?php

namespace App\Domain\Devices\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Carbon\CarbonInterface;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * docs/data-model.md "devices" (spec section 6). `status` ("online" etc.)
 * and `sync_status` are deliberately independent columns — "online" never
 * implies "all credentials synced"; the UI must render them separately.
 */
/**
 * @property CarbonInterface|null $last_heartbeat_at
 * @property CarbonInterface|null $last_event_at
 */
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'site_id',
        'serial_number',
        'model',
        'firmware_version',
        'install_location',
        'reader_role',
        'device_timezone',
        'status',
        'last_heartbeat_at',
        'last_event_at',
        'sync_status',
        'connector_version',
    ];

    protected function casts(): array
    {
        return [
            'last_heartbeat_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return HasMany<DeviceCapability, $this>
     */
    public function capabilities(): HasMany
    {
        return $this->hasMany(DeviceCapability::class);
    }

    /**
     * @return HasMany<DeviceSyncCommand, $this>
     */
    public function syncCommands(): HasMany
    {
        return $this->hasMany(DeviceSyncCommand::class);
    }

    /**
     * @return HasMany<DeviceCheckpoint, $this>
     */
    public function checkpoints(): HasMany
    {
        return $this->hasMany(DeviceCheckpoint::class);
    }

    protected static function newFactory(): DeviceFactory
    {
        return DeviceFactory::new();
    }
}
