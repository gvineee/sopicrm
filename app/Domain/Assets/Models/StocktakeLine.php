<?php

namespace App\Domain\Assets\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Database\Factories\StocktakeLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/data-model.md "stocktake_lines" (spec section 9.6): a raw scan never
 * directly changes the accounting balance — `variance_approved_adjustment_*`
 * stays null until a real, separately-approved adjustment record exists.
 */
class StocktakeLine extends Model
{
    /** @use HasFactory<StocktakeLineFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'stocktake_id',
        'asset_id',
        'expected_quantity',
        'counted_quantity',
        'recount_of_line_id',
        'variance_approved_adjustment_type',
        'variance_approved_adjustment_id',
    ];

    protected function casts(): array
    {
        return [
            'expected_quantity' => 'decimal:2',
            'counted_quantity' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Stocktake, $this>
     */
    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class);
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<StocktakeLine, $this>
     */
    public function recountOf(): BelongsTo
    {
        return $this->belongsTo(StocktakeLine::class, 'recount_of_line_id');
    }

    protected static function newFactory(): StocktakeLineFactory
    {
        return StocktakeLineFactory::new();
    }
}
