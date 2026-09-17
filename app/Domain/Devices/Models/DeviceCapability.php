<?php

namespace App\Domain\Devices\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Database\Factories\DeviceCapabilityFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/data-model.md "device_capabilities" (spec section 6: "მოწყობილობის
 * მეხსიერების ზუსტი ლიმიტი არ გამოიგონო — წაიკითხე capability"). `read_at`
 * is when this snapshot was actually read live from the device.
 */
class DeviceCapability extends Model
{
    /** @use HasFactory<DeviceCapabilityFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'device_id',
        'capability_key',
        'capability_value',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'capability_value' => 'array',
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    protected static function newFactory(): DeviceCapabilityFactory
    {
        return DeviceCapabilityFactory::new();
    }
}
