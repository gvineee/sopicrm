<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Employees\Models\RateHistory;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Carbon\CarbonInterface;
use Database\Factories\TimesheetLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/data-model.md "timesheet_lines" (spec section 7 hard rule): the sum
 * of a day's lines' `payable_minutes` for one employee must not exceed that
 * day's approved `attendance_sessions.payable_minutes` total — enforced by
 * an Action-level aggregate check inside the approval transaction (a plain
 * CHECK constraint can't aggregate across sibling rows), per the data
 * model's own note that the Integration/Timesheets module agent confirms
 * which mechanism (trigger vs. `SELECT ... FOR UPDATE` aggregate) was
 * implemented.
 */
/** @property CarbonInterface $work_date */
class TimesheetLine extends Model
{
    /** @use HasFactory<TimesheetLineFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'timesheet_id',
        'work_date',
        'project_id',
        'attendance_session_id',
        'payable_minutes',
        'rate_type',
        'rate_snapshot_id',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
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
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<AttendanceSession, $this>
     */
    public function attendanceSession(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class);
    }

    /**
     * @return BelongsTo<RateHistory, $this>
     */
    public function rateSnapshot(): BelongsTo
    {
        return $this->belongsTo(RateHistory::class, 'rate_snapshot_id');
    }

    protected static function newFactory(): TimesheetLineFactory
    {
        return TimesheetLineFactory::new();
    }
}
