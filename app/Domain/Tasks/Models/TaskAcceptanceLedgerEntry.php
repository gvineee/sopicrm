<?php

namespace App\Domain\Tasks\Models;

use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 03-Construction-Task-Manager-Spec-KA.md §8's acceptance ledger. Append
 * only: a row is never edited or deleted, because the point of the ledger is
 * that the past stays readable. Cancelling an earlier acceptance means
 * writing a NEW `reversal` row that points at it, with a reason.
 *
 * @property numeric-string $quantity_delta
 * @property Carbon|null $recorded_at
 */
class TaskAcceptanceLedgerEntry extends Model
{
    use BelongsToOrganization, HasUuids, HasVersion;

    public const TYPE_ACCEPTANCE = 'acceptance';

    public const TYPE_RETURN = 'return';

    public const TYPE_REVERSAL = 'reversal';

    /** Defect rework on already-accepted volume — never new production (§8). */
    public const TYPE_REWORK = 'rework';

    protected $table = 'task_acceptance_ledger_entries';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'task_id',
        'task_submission_id',
        'entry_type',
        'quantity_delta',
        'actor_user_id',
        'actor_employee_id',
        'reason',
        'reverses_entry_id',
        'source_reference',
        'idempotency_key',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
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
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function actorEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'actor_employee_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function reversesEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }
}
