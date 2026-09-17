<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Devices\Models\Site;
use App\Domain\Employees\Models\Employee;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Database\Factories\AttendanceSessionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/data-model.md "attendance_sessions" — computed/derived, versioned/
 * re-computable (spec section 7). Overlapping sessions for the same
 * employee are blocked at the DB level (see the Attendance domain
 * migration's exclusion constraint). A missing clock-out never auto-pays a
 * full day: it stays `status=open` with `payable_minutes=null` and raises an
 * AttendanceAnomaly instead (spec: "ავტომატური სრული დღის დარიცხვა არ
 * ხდება").
 */
/**
 * @property Carbon $clock_in_at
 * @property Carbon|null $clock_out_at
 * @property Carbon $work_date
 */
class AttendanceSession extends Model
{
    /** @use HasFactory<AttendanceSessionFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'employee_id',
        'site_id',
        'project_id',
        'clock_in_event_id',
        'clock_out_event_id',
        'clock_in_at',
        'clock_out_at',
        'work_date',
        'raw_duration_minutes',
        'payable_minutes',
        'reconstruction_run_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
            'work_date' => 'date',
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
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<RawAccessEvent, $this>
     */
    public function clockInEvent(): BelongsTo
    {
        return $this->belongsTo(RawAccessEvent::class, 'clock_in_event_id');
    }

    /**
     * @return BelongsTo<RawAccessEvent, $this>
     */
    public function clockOutEvent(): BelongsTo
    {
        return $this->belongsTo(RawAccessEvent::class, 'clock_out_event_id');
    }

    /**
     * @return HasMany<AttendanceAnomaly, $this>
     */
    public function anomalies(): HasMany
    {
        return $this->hasMany(AttendanceAnomaly::class);
    }

    protected static function newFactory(): AttendanceSessionFactory
    {
        return AttendanceSessionFactory::new();
    }
}
