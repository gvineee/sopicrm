<?php

namespace App\Domain\Assets\Models;

use App\Domain\Employees\Models\Employee;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\CustodyTransactionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * docs/data-model.md "custody_transactions" (spec sections 9.2-9.4). Digital
 * confirmation is explicitly NOT a qualified electronic signature — UI copy
 * must never claim otherwise; only `received_confirmation_user_id` +
 * `received_confirmation_at` are recorded as the evidence. A direct
 * employee-to-employee transfer still records a full chain (both
 * `issuing_employee_id` and `receiving_employee_id`), never a silent
 * location field edit.
 *
 * `return_requested_at`/`return_requested_by_user_id` added by
 * 2026_09_16_150000_add_return_request_to_custody_transactions_table.php —
 * spec section 9.6 employee self-service "request a return"; purely
 * informational/notification-triggering, never itself a return (see
 * App\Domain\Assets\Actions\ReturnCustodyAction for the only path that
 * actually mutates custody).
 */
/**
 * @property CarbonInterface $occurred_at
 * @property CarbonInterface|null $expected_return_at
 * @property CarbonInterface|null $return_requested_at
 * @property list<string> $photo_attachment_ids
 * @property CarbonInterface|null $received_confirmation_at
 */
class CustodyTransaction extends Model
{
    /** @use HasFactory<CustodyTransactionFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'type',
        'issuing_warehouse_id',
        'issuing_employee_id',
        'receiving_employee_id',
        'receiving_warehouse_id',
        'project_id',
        'occurred_at',
        'expected_return_at',
        'return_requested_at',
        'return_requested_by_user_id',
        'condition_at_transaction',
        'accessories_note',
        'photo_attachment_ids',
        'comment',
        'issued_by_user_id',
        'received_confirmation_user_id',
        'received_confirmation_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'expected_return_at' => 'datetime',
            'return_requested_at' => 'datetime',
            'photo_attachment_ids' => 'array',
            'received_confirmation_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function issuingEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'issuing_employee_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function receivingEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'receiving_employee_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receivedConfirmationBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_confirmation_user_id');
    }

    /**
     * @return HasMany<CustodyLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CustodyLine::class);
    }

    /**
     * @return HasMany<Acknowledgement, $this>
     */
    public function acknowledgements(): HasMany
    {
        return $this->hasMany(Acknowledgement::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function returnRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'return_requested_by_user_id');
    }

    protected static function newFactory(): CustodyTransactionFactory
    {
        return CustodyTransactionFactory::new();
    }
}
