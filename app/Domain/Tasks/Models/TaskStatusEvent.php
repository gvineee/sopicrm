<?php

namespace App\Domain\Tasks\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * database/migrations/2026_09_17_110000_add_tasks_workflow_extensions.php
 * "task_status_events" — append-only history of every task state
 * transition (spec section 10: "სრული ისტორია დარჩეს შენარჩუნებული").
 * Written exclusively by App\Domain\Tasks\Actions\RecordsTaskStatusEvent
 * (via each transition Action), never constructed ad hoc, so every
 * transition is guaranteed to leave a trail.
 */
class TaskStatusEvent extends Model
{
    use BelongsToOrganization, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'organization_id',
        'task_id',
        'from_status',
        'to_status',
        'actor_user_id',
        'reason',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
