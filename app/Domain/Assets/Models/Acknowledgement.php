<?php

namespace App\Domain\Assets\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Models\User;
use Database\Factories\AcknowledgementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/data-model.md "acknowledgements".
 */
class Acknowledgement extends Model
{
    /** @use HasFactory<AcknowledgementFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'custody_transaction_id',
        'acknowledged_by_user_id',
        'acknowledged_at',
        'role_at_time',
    ];

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    protected static function newFactory(): AcknowledgementFactory
    {
        return AcknowledgementFactory::new();
    }
}
