<?php

namespace App\Domain\Auth\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Models\User;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/data-model.md "memberships" — which organizations a user account can
 * access at all, ahead of the P4 multi-org UX.
 */
class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'user_id',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * See App\Domain\Auth\Models\Organization::newFactory()'s docblock.
     *
     * @return MembershipFactory
     */
    protected static function newFactory()
    {
        return MembershipFactory::new();
    }
}
