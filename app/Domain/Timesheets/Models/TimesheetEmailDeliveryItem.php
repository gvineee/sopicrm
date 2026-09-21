<?php

namespace App\Domain\Timesheets\Models;

use App\Domain\Attendance\Models\Timesheet;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Models\Attachment;
use Database\Factories\TimesheetEmailDeliveryItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TIMESHEET-EMAIL-02: one bundled-mode delivery's individual timesheet
 * attachment. See the creating migration's docblock.
 */
class TimesheetEmailDeliveryItem extends Model
{
    /** @use HasFactory<TimesheetEmailDeliveryItemFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'delivery_id',
        'timesheet_id',
        'timesheet_version_at_send',
        'attachment_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TimesheetEmailDelivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(TimesheetEmailDelivery::class, 'delivery_id');
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

    protected static function newFactory(): TimesheetEmailDeliveryItemFactory
    {
        return TimesheetEmailDeliveryItemFactory::new();
    }
}
