<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\PayRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * docs/data-model.md "pay_runs" (spec section 8). `policy_version_snapshot`
 * records the rounding rule + daily-policy thresholds actually used for
 * this run, since policy is config and the version used must never be
 * assumed from "whatever config is active today."
 */
/**
 * @property array<string, mixed>|null $policy_version_snapshot
 * @property CarbonInterface|null $calculated_at
 * @property CarbonInterface|null $approved_at
 */
class PayRun extends Model
{
    /** @use HasFactory<PayRunFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'pay_period_id',
        'status',
        'calculated_at',
        'calculated_by_user_id',
        'approved_at',
        'approved_by_user_id',
        'policy_version_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'calculated_at' => 'datetime',
            'approved_at' => 'datetime',
            'policy_version_snapshot' => 'array',
        ];
    }

    /**
     * @return BelongsTo<PayPeriod, $this>
     */
    public function payPeriod(): BelongsTo
    {
        return $this->belongsTo(PayPeriod::class);
    }

    /**
     * @return HasMany<PayRunLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PayRunLine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function calculatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calculated_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    protected static function newFactory(): PayRunFactory
    {
        return PayRunFactory::new();
    }
}
