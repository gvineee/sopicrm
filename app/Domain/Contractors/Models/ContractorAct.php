<?php

namespace App\Domain\Contractors\Models;

use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A "completed-volume act": a contractor's claimed unit of work, entered by
 * our own staff (no contractor-facing login exists) and mirroring
 * App\Domain\Tasks\Models\TaskSubmission's submit -> accept/return workflow.
 * Accepting an act never touches the referenced Task's own status/quantity —
 * this is a parallel, contractor-billing-only artifact (see plan Context).
 */
/**
 * @property Carbon|null $submitted_at
 * @property list<string> $evidence_attachment_ids
 */
class ContractorAct extends Model
{
    use BelongsToOrganization, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'contractor_id',
        'contract_id',
        'project_id',
        'task_id',
        'submitted_by_user_id',
        'description',
        'quantity',
        'evidence_attachment_ids',
        'submitted_at',
        'status',
        'returned_reason',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'evidence_attachment_ids' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Contractor, $this> */
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /** @return BelongsTo<ContractorContract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(ContractorContract::class, 'contract_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<User, $this> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /** @return HasOne<ContractorActAcceptance, $this> */
    public function acceptance(): HasOne
    {
        return $this->hasOne(ContractorActAcceptance::class);
    }
}
