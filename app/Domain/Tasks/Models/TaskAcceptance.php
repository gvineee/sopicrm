<?php

namespace App\Domain\Tasks\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Models\User;
use Database\Factories\TaskAcceptanceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/data-model.md "task_acceptances" (spec section 10 hard rule):
 * `accepted_quantity` <= the submission's `submitted_quantity`; the unique
 * `task_submission_id` (see the Projects & Tasks migration) makes acceptance
 * idempotent — `tasks.accepted_quantity` is only ever incremented once per
 * acceptance row, via a Domain Action that recomputes the task total by
 * summing acceptances, never by repeated `+=` mutation.
 */
class TaskAcceptance extends Model
{
    /** @use HasFactory<TaskAcceptanceFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'task_submission_id',
        'accepted_by_user_id',
        'accepted_quantity',
        'accepted_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'accepted_quantity' => 'decimal:2',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TaskSubmission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(TaskSubmission::class, 'task_submission_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    protected static function newFactory(): TaskAcceptanceFactory
    {
        return TaskAcceptanceFactory::new();
    }
}
