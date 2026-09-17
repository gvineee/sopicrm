<?php

namespace App\Domain\Assets\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Database\Factories\CustodyLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/data-model.md "custody_lines" (spec section 9.6). A `damaged` return
 * condition routes the asset's current condition to `under_repair`/
 * quarantine rather than back to `good`/available — a Domain Action
 * concern, not a DB trigger.
 */
class CustodyLine extends Model
{
    /** @use HasFactory<CustodyLineFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'custody_transaction_id',
        'asset_id',
        'quantity',
        'returned_quantity',
        'line_condition',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'returned_quantity' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<CustodyTransaction, $this>
     */
    public function custodyTransaction(): BelongsTo
    {
        return $this->belongsTo(CustodyTransaction::class);
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    protected static function newFactory(): CustodyLineFactory
    {
        return CustodyLineFactory::new();
    }
}
