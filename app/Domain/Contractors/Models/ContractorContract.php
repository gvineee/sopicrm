<?php

namespace App\Domain\Contractors\Models;

use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Domain\Shared\Models\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * State machine: draft -> pending_approval -> active -> closed. Only `active`
 * contracts accept new ContractorAct/ContractorPayment rows — see
 * App\Domain\Contractors\Exceptions\InvalidContractorContractStateException.
 * The approver must not be the contract's own submitter — see
 * App\Policies\ContractorContractPolicy::approve (self-approval-forbidden
 * precedent, matches Payroll's rule in docs/decisions.md).
 */
/**
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property Carbon|null $submitted_for_approval_at
 * @property Carbon|null $approved_at
 */
class ContractorContract extends Model
{
    use BelongsToOrganization, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'contractor_id',
        'project_id',
        'contract_number',
        'title',
        'description',
        'rate_type',
        'rate_amount',
        'total_amount',
        'currency',
        'unit',
        'starts_on',
        'ends_on',
        'status',
        'submitted_for_approval_at',
        'submitted_by_user_id',
        'approved_at',
        'approved_by_user_id',
        'rejection_reason',
        'terms',
        'contract_attachment_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'rate_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'submitted_for_approval_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Contractor, $this> */
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Attachment, $this> */
    public function contractAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'contract_attachment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<ContractorAct, $this> */
    public function acts(): HasMany
    {
        return $this->hasMany(ContractorAct::class, 'contract_id');
    }

    /** @return HasMany<ContractorPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(ContractorPayment::class, 'contract_id');
    }
}
