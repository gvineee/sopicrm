<?php

namespace App\Domain\Assets\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Domain\Shared\Models\Attachment;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * docs/data-model.md "assets" (spec section 9). `qr_token` is an opaque
 * reference only — resolving it always re-runs the Policy check
 * server-side; the token itself grants no access. Individually-tracked
 * assets always represent quantity 1; `quantity_on_hand` is only meaningful
 * for `tracking_type IN ('quantity','consumable')`.
 */
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'name',
        'category',
        'tracking_type',
        'inventory_code',
        'initial_location_id',
        'condition',
        'brand',
        'model',
        'serial_number',
        'purchased_at',
        'purchase_price',
        'purchase_currency',
        'supplier',
        'warranty_until',
        'manual_attachment_id',
        'bundle_contents',
        'ownership',
        'calibration_due_at',
        'service_due_at',
        'quantity_on_hand',
        'qr_token',
    ];

    protected function casts(): array
    {
        return [
            'purchased_at' => 'date',
            'purchase_price' => 'decimal:2',
            'warranty_until' => 'date',
            'bundle_contents' => 'array',
            'calibration_due_at' => 'datetime',
            'service_due_at' => 'datetime',
            'quantity_on_hand' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<AssetLocation, $this>
     */
    public function initialLocation(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'initial_location_id');
    }

    /**
     * @return HasMany<AssetLocation, $this>
     */
    public function locationHistory(): HasMany
    {
        return $this->hasMany(AssetLocation::class);
    }

    /**
     * @return HasOne<AssetLocation, $this>
     */
    public function currentLocation(): HasOne
    {
        return $this->hasOne(AssetLocation::class)->where('is_current', true);
    }

    /**
     * @return HasOne<AssetActiveCustody, $this>
     */
    public function activeCustody(): HasOne
    {
        return $this->hasOne(AssetActiveCustody::class);
    }

    /**
     * @return BelongsTo<Attachment, $this>
     */
    public function manual(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'manual_attachment_id');
    }

    protected static function newFactory(): AssetFactory
    {
        return AssetFactory::new();
    }
}
