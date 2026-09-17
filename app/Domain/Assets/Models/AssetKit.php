<?php

namespace App\Domain\Assets\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use Database\Factories\AssetKitFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/data-model.md "asset_kits".
 */
class AssetKit extends Model
{
    /** @use HasFactory<AssetKitFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'kit_asset_id',
        'component_asset_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function kitAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'kit_asset_id');
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function componentAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'component_asset_id');
    }

    protected static function newFactory(): AssetKitFactory
    {
        return AssetKitFactory::new();
    }
}
