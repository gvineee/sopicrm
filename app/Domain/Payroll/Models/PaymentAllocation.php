<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Database\Factories\PaymentAllocationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/data-model.md "payment_allocations". Outstanding balance is always
 * computed (not stored) as: approved `pay_run_lines.net_amount` − Σ
 * (allocations of type `payment_to_earnings`) − Σ (allocations of type
 * `advance_deduction`) — spec section 8's exact formula. That computation
 * is a Domain service concern, not this model.
 */
class PaymentAllocation extends Model
{
    /** @use HasFactory<PaymentAllocationFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'payment_id',
        'pay_run_line_id',
        'advance_id',
        'allocated_amount',
        'allocation_type',
    ];

    protected function casts(): array
    {
        return [
            'allocated_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<PayRunLine, $this>
     */
    public function payRunLine(): BelongsTo
    {
        return $this->belongsTo(PayRunLine::class);
    }

    /**
     * @return BelongsTo<Advance, $this>
     */
    public function advance(): BelongsTo
    {
        return $this->belongsTo(Advance::class);
    }

    protected static function newFactory(): PaymentAllocationFactory
    {
        return PaymentAllocationFactory::new();
    }
}
