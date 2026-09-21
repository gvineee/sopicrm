<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Devices\Models\Site;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Domain\Shared\Models\Attachment;
use App\Models\User;
use Database\Factories\AttendanceAdjustmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/data-model.md "attendance_adjustments" (spec section 7). The
 * original session row is never overwritten — `original_session_id` is
 * reference-only. A late-arriving raw event for an already-locked timesheet
 * period sets `for_locked_period=true` instead of silently mutating locked
 * history.
 */
/**
 * @property Carbon $work_date
 * @property Carbon|null $corrected_clock_in_at
 * @property Carbon|null $corrected_clock_out_at
 */
class AttendanceAdjustment extends Model
{
    /** @use HasFactory<AttendanceAdjustmentFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'employee_id',
        'work_date',
        'site_id',
        'corrected_clock_in_at',
        'corrected_clock_out_at',
        'corrected_hours',
        'reason',
        'evidence_attachment_id',
        'requested_by_user_id',
        'approved_by_user_id',
        'status',
        'original_session_id',
        'for_locked_period',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'corrected_clock_in_at' => 'datetime',
            'corrected_clock_out_at' => 'datetime',
            'corrected_hours' => 'decimal:2',
            'for_locked_period' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<Attachment, $this>
     */
    public function evidenceAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'evidence_attachment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * @return BelongsTo<AttendanceSession, $this>
     */
    public function originalSession(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class, 'original_session_id');
    }

    protected static function newFactory(): AttendanceAdjustmentFactory
    {
        return AttendanceAdjustmentFactory::new();
    }
}
