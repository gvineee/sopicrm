<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Domain\Shared\Models\Attachment;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * docs/data-model.md "payments" (spec section 8 hard rule: "pending
 * გადახდა paid არ ჩაითვლოს"). Hard constraint reminder (outer task, not
 * only the spec): this system never sends a real payment — rows here are a
 * record-keeping ledger of payments made through an external process.
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'employee_id',
        'pay_run_id',
        'paid_at',
        'amount',
        'currency',
        'method',
        'reference',
        'evidence_attachment_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'amount' => 'decimal:2',
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
     * @return BelongsTo<PayRun, $this>
     */
    public function payRun(): BelongsTo
    {
        return $this->belongsTo(PayRun::class);
    }

    /**
     * @return BelongsTo<Attachment, $this>
     */
    public function evidenceAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'evidence_attachment_id');
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }
}
