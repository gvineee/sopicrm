<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\RateHistory;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Domain\Shared\Models\Approval;
use Database\Factories\PayRunLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/data-model.md "pay_run_lines" (spec section 8 hard rule): an
 * employee's day-unit total across all sites/projects on one work-date
 * defaults to a max of 1.0 unless an explicit, separately-approved
 * exception exists — that aggregate check is a Domain calculation Action
 * concern; `exceeds_daily_cap` + `daily_cap_exception_approval_id` record
 * that an exception was granted, once granted, so its exercise is
 * auditable. Final GEL rounding is half-up to 0.01 at the line level; a
 * split across multiple project lines uses deterministic largest-remainder
 * allocation so the lines always sum exactly to the pre-split total.
 */
/**
 * @property string $quantity
 * @property string $gross_amount
 * @property string $adjustments_amount
 * @property string $net_amount
 * @property bool $exceeds_daily_cap
 */
class PayRunLine extends Model
{
    /** @use HasFactory<PayRunLineFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'pay_run_id',
        'employee_id',
        'project_id',
        'basis',
        'quantity',
        'rate_snapshot_id',
        'formula_applied',
        'gross_amount',
        'adjustments_amount',
        'net_amount',
        'exceeds_daily_cap',
        'daily_cap_exception_approval_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'adjustments_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'exceeds_daily_cap' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PayRun, $this>
     */
    public function payRun(): BelongsTo
    {
        return $this->belongsTo(PayRun::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<RateHistory, $this>
     */
    public function rateSnapshot(): BelongsTo
    {
        return $this->belongsTo(RateHistory::class, 'rate_snapshot_id');
    }

    /**
     * @return BelongsTo<Approval, $this>
     */
    public function dailyCapExceptionApproval(): BelongsTo
    {
        return $this->belongsTo(Approval::class, 'daily_cap_exception_approval_id');
    }

    protected static function newFactory(): PayRunLineFactory
    {
        return PayRunLineFactory::new();
    }
}
