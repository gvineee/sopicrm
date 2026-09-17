<?php

namespace App\Domain\Assets\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Models\User;
use Database\Factories\AssetIncidentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/data-model.md "asset_incidents" (spec section 9.5): a write-off
 * requires an authorized Approval record and never deletes the asset —
 * history remains.
 */
class AssetIncident extends Model
{
    /** @use HasFactory<AssetIncidentFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'asset_id',
        'incident_type',
        'occurred_at',
        'location',
        'description',
        'photo_attachment_ids',
        'reported_by_user_id',
        'estimated_repair_cost',
        'reviewed_by_user_id',
        'decision',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'photo_attachment_ids' => 'array',
            'estimated_repair_cost' => 'decimal:2',
            'decided_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    protected static function newFactory(): AssetIncidentFactory
    {
        return AssetIncidentFactory::new();
    }
}
