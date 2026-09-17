<?php

namespace App\Domain\Assets\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Database\Factories\AssetActiveCustodyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/data-model.md "custody_lines" hard-rule note: a lightweight marker
 * table (one row per asset) used to enforce "only one of two simultaneous
 * issues succeeds" — the issue Action does `SELECT ... FOR UPDATE` on this
 * row inside a DB transaction before creating a CustodyTransaction, and the
 * partial unique index on (organization_id, asset_id) WHERE status IN
 * ('issued','awaiting_receipt') is the DB-level backstop. This model is
 * intentionally NOT the source of truth for custody history — that is
 * `custody_transactions`/`custody_lines`; this table only ever holds the
 * asset's current lock state.
 */
class AssetActiveCustody extends Model
{
    /** @use HasFactory<AssetActiveCustodyFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    protected $table = 'asset_active_custody';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'asset_id',
        'status',
        'current_custody_transaction_id',
    ];

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<CustodyTransaction, $this>
     */
    public function currentCustodyTransaction(): BelongsTo
    {
        return $this->belongsTo(CustodyTransaction::class, 'current_custody_transaction_id');
    }

    protected static function newFactory(): AssetActiveCustodyFactory
    {
        return AssetActiveCustodyFactory::new();
    }
}
