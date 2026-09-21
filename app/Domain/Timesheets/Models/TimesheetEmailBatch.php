<?php

namespace App\Domain\Timesheets\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\TimesheetEmailBatchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TIMESHEET-EMAIL-02: groups the TimesheetEmailDelivery rows created from
 * one "send selected timesheets" request. See the creating migration's
 * docblock for `mode`/`skipped_details`'s exact meaning.
 */
/**
 * @property list<array{timesheet_id: ?string, recipient_email: ?string, reason: string}>|null $skipped_details
 * @property CarbonInterface $created_at
 */
class TimesheetEmailBatch extends Model
{
    /** @use HasFactory<TimesheetEmailBatchFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'mode',
        'status',
        'requested_by_user_id',
        'bundled_recipient_email',
        'bundled_recipient_user_id',
        'total_count',
        'skipped_details',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'skipped_details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<TimesheetEmailDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(TimesheetEmailDelivery::class, 'batch_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    protected static function newFactory(): TimesheetEmailBatchFactory
    {
        return TimesheetEmailBatchFactory::new();
    }
}
