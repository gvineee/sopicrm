<?php

namespace App\Domain\Auth\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\UserPermissionDenialFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADMIN-02: an explicit, per-user "this permission is withheld regardless
 * of role" row. See the creating migration's docblock for the precedence
 * this participates in — a row here is the single most authoritative
 * authorization check in the app (App\Providers\AppServiceProvider::boot()).
 * Create/delete only, no update — a changed reason is a new decision, not
 * an edit of the old one, so this model has no `updated_at`.
 *
 * @property CarbonInterface $created_at
 */
class UserPermissionDenial extends Model
{
    /** @use HasFactory<UserPermissionDenialFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'user_id',
        'permission_name',
        'reason',
        'created_by_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected static function newFactory(): UserPermissionDenialFactory
    {
        return UserPermissionDenialFactory::new();
    }
}
