<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Database\Factories\PayPeriodFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/data-model.md "pay_periods" (spec section 8).
 */
/**
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 */
class PayPeriod extends Model
{
    /** @use HasFactory<PayPeriodFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'starts_on',
        'ends_on',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /**
     * @return HasMany<PayRun, $this>
     */
    public function payRuns(): HasMany
    {
        return $this->hasMany(PayRun::class);
    }

    protected static function newFactory(): PayPeriodFactory
    {
        return PayPeriodFactory::new();
    }
}
