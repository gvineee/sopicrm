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

/**
 * TIMESHEET-EMAIL-01 (docs/claude-platform-completion-2026-09-21.md):
 * one row per attempt to email a Timesheet snapshot to one recipient.
 * `status` is deliberately only `queued`/`sent`/`failed` — see the creating
 * migration's docblock for why `delivered` is never a valid value here.
 */
/**
 * @property CarbonInterface|null $sent_at
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
        'timesheet_id',
        'timesheet_version_at_send',
        'attachment_id',
        'recipient_email',
        'recipient_user_id',
        'subject',
        'status',
        'failed_reason',
        'sent_at',
        'requested_by_user_id',
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
