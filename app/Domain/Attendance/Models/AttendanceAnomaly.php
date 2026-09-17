<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Devices\Models\Device;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Models\User;
use Database\Factories\AttendanceAnomalyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/data-model.md "attendance_anomalies" (spec section 7).
 *
 * `employee_id` was widened to nullable, and `device_id`/`raw_access_event_id`
 * added, by the Devices module's additive migration
 * (2026_09_16_100000_add_device_context_to_attendance_anomalies_table.php,
 * see docs/decisions.md): spec section 6 requires flagging `clock_drift`,
 * `out_of_order_events` and `data_gap` anomalies at the device/event-stream
 * level, including for an unmatched-card event that never resolves to an
 * Employee (spec explicit: unknown cards never auto-create one) — those
 * rows carry `device_id`/`raw_access_event_id` instead of a fabricated
 * employee_id.
 */
class AttendanceAnomaly extends Model
{
    /** @use HasFactory<AttendanceAnomalyFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'employee_id',
        'attendance_session_id',
        'device_id',
        'raw_access_event_id',
        'anomaly_type',
        'detected_at',
        'details',
        'resolved_at',
        'resolution_note',
        'resolved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'details' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<AttendanceAnomaly>  $query
     * @return Builder<AttendanceAnomaly>
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<AttendanceSession, $this>
     */
    public function attendanceSession(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return BelongsTo<RawAccessEvent, $this>
     */
    public function rawAccessEvent(): BelongsTo
    {
        return $this->belongsTo(RawAccessEvent::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    protected static function newFactory(): AttendanceAnomalyFactory
    {
        return AttendanceAnomalyFactory::new();
    }
}
