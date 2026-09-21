<?php

namespace App\Domain\Devices\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use Database\Factories\DoorFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Door extends Model
{
    /** @use HasFactory<DoorFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['organization_id', 'site_id', 'zone_id', 'device_id', 'name', 'direction', 'lock_configuration', 'rex_configuration', 'sensor_configuration', 'enabled'];

    protected function casts(): array
    {
        return ['lock_configuration' => 'array', 'rex_configuration' => 'array', 'sensor_configuration' => 'array', 'enabled' => 'boolean'];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<Zone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    protected static function newFactory(): DoorFactory
    {
        return DoorFactory::new();
    }
}
