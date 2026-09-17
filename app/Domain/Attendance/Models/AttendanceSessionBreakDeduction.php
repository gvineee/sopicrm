<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use Database\Factories\AttendanceSessionBreakDeductionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/data-model.md — the break-deduction idempotency join table described
 * under "shift_templates": a given (session, shift template, break window)
 * is applied to payable_minutes at most once per recompute pass, enforced by
 * this table's unique triple.
 */
class AttendanceSessionBreakDeduction extends Model
{
    /** @use HasFactory<AttendanceSessionBreakDeductionFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'attendance_session_id',
        'shift_template_id',
        'break_window_key',
        'deducted_minutes',
    ];

    /**
     * @return BelongsTo<AttendanceSession, $this>
     */
    public function attendanceSession(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class);
    }

    /**
     * @return BelongsTo<ShiftTemplate, $this>
     */
    public function shiftTemplate(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class);
    }

    protected static function newFactory(): AttendanceSessionBreakDeductionFactory
    {
        return AttendanceSessionBreakDeductionFactory::new();
    }
}
