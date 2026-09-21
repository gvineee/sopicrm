<?php

namespace App\Domain\Devices\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use Database\Factories\ZoneFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Zone extends Model
{
    /** @use HasFactory<ZoneFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['organization_id', 'site_id', 'name', 'description', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return HasMany<Door, $this>
     */
    public function doors(): HasMany
    {
        return $this->hasMany(Door::class);
    }

    /**
     * Every device reachable through one of this zone's doors — a device
     * is not owned by a zone directly, only via the door it's mounted on.
     *
     * @return HasManyThrough<Device, Door, $this>
     */
    public function devices(): HasManyThrough
    {
        return $this->hasManyThrough(Device::class, Door::class, 'zone_id', 'id', 'id', 'device_id');
    }

    protected static function newFactory(): ZoneFactory
    {
        return ZoneFactory::new();
    }
}
