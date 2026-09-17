<?php

namespace App\Domain\Tasks\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use Database\Factories\TaskDependencyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/data-model.md "task_dependencies" (spec section 10 hard rule): no
 * dependency cycles, enforced at the Domain Action level via a recursive
 * CTE check inside the same transaction as the insert — this model only
 * exposes the edge, it does not itself run that check.
 */
class TaskDependency extends Model
{
    /** @use HasFactory<TaskDependencyFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'task_id',
        'depends_on_task_id',
    ];

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'depends_on_task_id');
    }

    protected static function newFactory(): TaskDependencyFactory
    {
        return TaskDependencyFactory::new();
    }
}
