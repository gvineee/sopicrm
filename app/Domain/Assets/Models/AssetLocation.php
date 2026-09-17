<?php

namespace App\Domain\Assets\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Database\Factories\AssetLocationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * docs/data-model.md "asset_locations". Exactly one `is_current=true` row
 * per asset (partial unique index) — history is preserved via non-current
 * rows, never overwritten.
 */
class AssetLocation extends Model
{
    /** @use HasFactory<AssetLocationFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'locatable_type',
        'locatable_id',
        'asset_id',
        'as_of',
        'is_current',
    ];

    protected function casts(): array
    {
        return [
            'as_of' => 'datetime',
            'is_current' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function locatable(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function newFactory(): AssetLocationFactory
    {
        return AssetLocationFactory::new();
    }
}
