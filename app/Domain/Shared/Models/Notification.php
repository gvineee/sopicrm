<?php

namespace App\Domain\Shared\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Models\User;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/data-model.md "notifications" (spec section 17 P1 list). Named
 * `App\Domain\Shared\Models\Notification` (NOT `Illuminate\Notifications...`)
 * to avoid colliding with Laravel's own notification system — this is the
 * queryable in-app notification feed row, not a `Notifiable` channel
 * delivery. `dedup_key` is required (never null); a notification with no
 * natural dedup key uses its own generated UUID as the key (routine
 * decision recorded on the table's own migration).
 */
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    protected $table = 'notifications';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'recipient_user_id',
        'type',
        'payload',
        'dedup_key',
        'read_at',
        'deep_link',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'read_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    protected static function newFactory(): NotificationFactory
    {
        return NotificationFactory::new();
    }
}
