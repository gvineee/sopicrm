<?php

namespace App\Domain\Contractors\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Mirrors App\Domain\Tasks\Models\TaskAcceptance. `contractor_act_id` is
 * DB-unique (see the contractors migration) — the idempotency guard that
 * makes double-accepting the same act impossible, which is what
 * App\Domain\Contractors\Services\ContractorBalanceService relies on when
 * summing `accepted_amount` per contract.
 */
/** @property Carbon|null $accepted_at */
class ContractorActAcceptance extends Model
{
    use BelongsToOrganization, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'contractor_act_id',
        'accepted_by_user_id',
        'accepted_quantity',
        'accepted_amount',
        'accepted_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'accepted_quantity' => 'decimal:2',
            'accepted_amount' => 'decimal:2',
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ContractorAct, $this> */
    public function act(): BelongsTo
    {
        return $this->belongsTo(ContractorAct::class, 'contractor_act_id');
    }

    /** @return BelongsTo<User, $this> */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }
}
