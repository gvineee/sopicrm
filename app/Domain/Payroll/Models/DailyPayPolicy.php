<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Models\User;
use Database\Factories\DailyPayPolicyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Additive migration 2026_09_16_110000 (see docs/decisions.md). Spec section
 * 8 hard rule: the full/half-day threshold, minimum-attendance rule, and
 * incomplete-day behavior must be configurable, never hardcoded. One row
 * per organization; `is_confirmed=false` (the default, and the state of any
 * newly-created row) means daily-basis payroll calculation is BLOCKED — see
 * App\Domain\Payroll\Actions\CalculatePayRunAction — rather than silently
 * using an unconfirmed/placeholder threshold.
 */
class DailyPayPolicy extends Model
{
    /** @use HasFactory<DailyPayPolicyFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'full_day_threshold_minutes',
        'half_day_threshold_minutes',
        'minimum_attendance_minutes',
        'incomplete_day_behavior',
        'max_day_units_per_work_date',
        'is_confirmed',
        'confirmed_by_user_id',
        'confirmed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'max_day_units_per_work_date' => 'decimal:2',
            'is_confirmed' => 'boolean',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    /**
     * A snapshot of exactly the fields that drive calculation, for storing
     * on PayRun.policy_version_snapshot (spec section 8: "პოლიტიკის
     * ვერსია" must be recorded on the run, never re-derived from "whatever
     * is active today").
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'daily_pay_policy_id' => $this->id,
            'version' => $this->version,
            'full_day_threshold_minutes' => $this->full_day_threshold_minutes,
            'half_day_threshold_minutes' => $this->half_day_threshold_minutes,
            'minimum_attendance_minutes' => $this->minimum_attendance_minutes,
            'incomplete_day_behavior' => $this->incomplete_day_behavior,
            'max_day_units_per_work_date' => (string) $this->max_day_units_per_work_date,
            'is_confirmed' => $this->is_confirmed,
            'rounding_rule' => 'half_up_0.01_gel',
        ];
    }

    protected static function newFactory(): DailyPayPolicyFactory
    {
        return DailyPayPolicyFactory::new();
    }
}
