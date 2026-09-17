<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Models\User;
use Database\Factories\PayAdjustmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/data-model.md "pay_adjustments" (spec section 8 hard rule:
 * "დაზიანებული ხელსაწყოს, ჯარიმის ან ვალის ავტომატური ჩამოჭრა
 * აკრძალულია"): deduction-type adjustments only ever get created through an
 * explicit, separately-permissioned human-approved action, never an
 * automated job. Corrections to an already-approved/locked period are new
 * reversal rows referencing the original via `reverses_pay_adjustment_id`,
 * never an edit to the locked line.
 */
class PayAdjustment extends Model
{
    /** @use HasFactory<PayAdjustmentFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'pay_run_line_id',
        'employee_id',
        'type',
        'amount',
        'reason',
        'approved_by_user_id',
        'requires_separate_permission',
        'reverses_pay_adjustment_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'requires_separate_permission' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PayRunLine, $this>
     */
    public function payRunLine(): BelongsTo
    {
        return $this->belongsTo(PayRunLine::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * @return BelongsTo<PayAdjustment, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(PayAdjustment::class, 'reverses_pay_adjustment_id');
    }

    protected static function newFactory(): PayAdjustmentFactory
    {
        return PayAdjustmentFactory::new();
    }
}
