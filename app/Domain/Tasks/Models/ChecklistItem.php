<?php

namespace App\Domain\Tasks\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Models\User;
use Database\Factories\ChecklistItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/data-model.md "checklist_items".
 */
class ChecklistItem extends Model
{
    /** @use HasFactory<ChecklistItemFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'task_id',
        'label',
        'is_required',
        'is_checked',
        'checked_by_user_id',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_checked' => 'boolean',
            'checked_at' => 'datetime',
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
    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by_user_id');
    }

    protected static function newFactory(): ChecklistItemFactory
    {
        return ChecklistItemFactory::new();
    }
}
