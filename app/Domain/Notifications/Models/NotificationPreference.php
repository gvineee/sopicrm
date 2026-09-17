<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Models\User;
use Database\Factories\NotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (organization, user) — spec section 17 "მომხმარებლის
 * preferences". `muted_types` holds the subset of
 * App\Domain\Notifications\Support\NotificationType constants this user has
 * muted; absence from the list means notifications of that type are created
 * normally. See database/migrations/2026_09_17_160000_... for why no
 * push/email columns exist yet (P2 scope).
 */
/** @property list<string> $muted_types */
class NotificationPreference extends Model
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'user_id',
        'muted_types',
    ];

    protected function casts(): array
    {
        return [
            'muted_types' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasMuted(string $type): bool
    {
        return in_array($type, $this->muted_types ?? [], true);
    }

    protected static function newFactory(): NotificationPreferenceFactory
    {
        return NotificationPreferenceFactory::new();
    }
}
