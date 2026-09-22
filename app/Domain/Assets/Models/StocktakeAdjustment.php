<?php

namespace App\Domain\Assets\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Models\User;
use Database\Factories\StocktakeAdjustmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * See 2026_09_17_090000_create_stocktake_adjustments_table.php: the only
 * record type that ever mutates a ledger balance (quantity_on_hand) or an
 * individually-tracked asset's state as a result of a stocktake variance
 * (spec section 9.6). A raw QR scan (StocktakeLine.counted_quantity) never
 * does this directly — only an approved adjustment does.
 */
/**
 * @property Carbon|null $approved_at
 */
class StocktakeAdjustment extends Model
{
    /** @use HasFactory<StocktakeAdjustmentFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'stocktake_line_id',
        'asset_id',
        'adjustment_type',
        'quantity_before',
        'quantity_after',
        'approved_by_user_id',
        'approved_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_before' => 'decimal:2',
            'quantity_after' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StocktakeLine, $this>
     */
    public function stocktakeLine(): BelongsTo
    {
        return $this->belongsTo(StocktakeLine::class);
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    protected static function newFactory(): StocktakeAdjustmentFactory
    {
        return StocktakeAdjustmentFactory::new();
    }
}
