<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\TelegramLinkFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * NOTIFY-01: a `telegram_chat_id` is never trusted as identity by itself —
 * see the migration's own docblock. `isLinked()` is the single place that
 * decides whether this row represents a completed link (chat id present
 * and the one-time code already consumed), used by
 * App\Domain\Notifications\Actions\SendTelegramReportAction before it ever
 * re-checks the user's live permissions.
 */
/**
 * @property CarbonInterface $link_code_expires_at
 * @property CarbonInterface|null $linked_at
 */
class TelegramLink extends Model
{
    /** @use HasFactory<TelegramLinkFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'user_id',
        'telegram_chat_id',
        'link_code',
        'link_code_expires_at',
        'linked_at',
    ];

    protected function casts(): array
    {
        return [
            'link_code_expires_at' => 'datetime',
            'linked_at' => 'datetime',
        ];
    }

    public function isLinked(): bool
    {
        return $this->telegram_chat_id !== null && $this->linked_at !== null;
    }

    public function isCodeUsable(): bool
    {
        return $this->linked_at === null && $this->link_code_expires_at->isFuture();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<TelegramReportDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(TelegramReportDelivery::class);
    }

    protected static function newFactory(): TelegramLinkFactory
    {
        return TelegramLinkFactory::new();
    }
}
