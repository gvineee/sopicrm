<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\TelegramReportDeliveryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NOTIFY-01: one row per attempted report send — the retry/history record
 * the ticket's acceptance line requires. `status` is queued/sent/failed
 * only, matching TIMESHEET-EMAIL-01's own "never claim delivered" rule.
 */
/** @property CarbonInterface|null $sent_at */
class TelegramReportDelivery extends Model
{
    /** @use HasFactory<TelegramReportDeliveryFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'telegram_link_id',
        'requested_by_user_id',
        'report_type',
        'status',
        'failed_reason',
        'sent_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TelegramLink, $this>
     */
    public function telegramLink(): BelongsTo
    {
        return $this->belongsTo(TelegramLink::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    protected static function newFactory(): TelegramReportDeliveryFactory
    {
        return TelegramReportDeliveryFactory::new();
    }
}
