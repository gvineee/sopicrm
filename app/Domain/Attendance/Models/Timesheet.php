<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Employees\Models\Employee;
use App\Domain\Payroll\Models\PayPeriod;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Models\User;
use Database\Factories\TimesheetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * docs/data-model.md "timesheets" (spec section 7). Approval snapshots
 * exactly which `attendance_sessions` rows + their `version`, and which
 * calculation-policy version, were used — so a later change to source data
 * never silently changes what was already approved. Rejected returns to
 * `draft` with `rejected_reason` populated.
 */
/** @property array<string, mixed>|null $source_sessions_version_snapshot */
class Timesheet extends Model
{
    /** @use HasFactory<TimesheetFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'employee_id',
        'pay_period_id',
        'status',
        'submitted_at',
        'submitted_by_user_id',
        'approved_at',
        'approved_by_user_id',
        'rejected_reason',
        'locked_at',
        'source_sessions_version_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'locked_at' => 'datetime',
            'source_sessions_version_snapshot' => 'array',
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
     * @return BelongsTo<PayPeriod, $this>
     */
    public function payPeriod(): BelongsTo
    {
        return $this->belongsTo(PayPeriod::class);
    }

    /**
     * @return HasMany<TimesheetLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(TimesheetLine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    protected static function newFactory(): TimesheetFactory
    {
        return TimesheetFactory::new();
    }
}
