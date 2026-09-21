<?php

namespace App\Domain\Timesheets\Models;

use App\Domain\Attendance\Models\Timesheet;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Models\Attachment;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\TimesheetEmailDeliveryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TIMESHEET-EMAIL-01 (docs/claude-platform-completion-2026-09-21.md):
 * one row per attempt to email a Timesheet snapshot to one recipient.
 * `status` is deliberately only `queued`/`sent`/`failed` — see the creating
 * migration's docblock for why `delivered` is never a valid value here.
 */
/**
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $cancelled_at
 * @property CarbonInterface $created_at
 */
class TimesheetEmailDelivery extends Model
{
    /** @use HasFactory<TimesheetEmailDeliveryFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'batch_id',
        'timesheet_id',
        'timesheet_version_at_send',
        'attachment_id',
        'recipient_email',
        'recipient_user_id',
        'subject',
        'status',
        'failed_reason',
        'sent_at',
        'cancelled_at',
        'requested_by_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TimesheetEmailBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(TimesheetEmailBatch::class, 'batch_id');
    }

    /**
     * TIMESHEET-EMAIL-02: populated only for a `bundled`-mode batch
     * delivery — see that migration's docblock. Empty for a plain
     * TIMESHEET-EMAIL-01 single send or a `per_employee`-mode batch
     * delivery (both fully described by this row's own
     * timesheet_id/attachment_id already).
     *
     * @return HasMany<TimesheetEmailDeliveryItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(TimesheetEmailDeliveryItem::class, 'delivery_id');
    }

    /**
     * TIMESHEET-EMAIL-02: true once `App\Jobs\Timesheets\
     * SendTimesheetEmailJob`'s idempotency check must also treat this
     * delivery as already resolved, without needing a 4th `status` value.
     */
    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * @return BelongsTo<Timesheet, $this>
     */
    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class);
    }

    /**
     * @return BelongsTo<Attachment, $this>
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    protected static function newFactory(): TimesheetEmailDeliveryFactory
    {
        return TimesheetEmailDeliveryFactory::new();
    }
}
