<?php

namespace App\Domain\Assets\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Models\User;
use Database\Factories\StocktakeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/data-model.md "stocktakes".
 */
/** @property Carbon|null $session_started_at */
class Stocktake extends Model
{
    /** @use HasFactory<StocktakeFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'scope_type',
        'scope_id',
        'session_started_at',
        'expected_snapshot',
        'status',
        'performed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'session_started_at' => 'datetime',
            'expected_snapshot' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    /**
     * @return HasMany<StocktakeLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StocktakeLine::class);
    }

    protected static function newFactory(): StocktakeFactory
    {
        return StocktakeFactory::new();
    }
}
