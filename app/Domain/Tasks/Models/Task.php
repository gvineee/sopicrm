<?php

namespace App\Domain\Tasks\Models;

use App\Domain\Employees\Models\Employee;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectLocation;
use App\Domain\Projects\Models\WorkPackage;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Domain\Shared\Models\Attachment;
use App\Domain\Shared\Models\DocumentRevision;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * docs/data-model.md "tasks" (spec section 10). Exactly one accountable
 * owner. A task binds to one specific `drawing_revision_id` — uploading a
 * newer drawing never silently re-points existing tasks. `accepted_quantity
 * <= planned_quantity` is enforced at the TaskAcceptance step (Domain
 * Action), not a blind DB CHECK here, since acceptance is cumulative across
 * possibly multiple submissions.
 */
/** @property numeric-string|null $planned_quantity */
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'project_id',
        'project_location_id',
        'work_package_id',
        'title',
        'description',
        'accountable_owner_employee_id',
        'priority',
        'due_at',
        'planned_duration_minutes',
        'unit',
        'planned_quantity',
        'accepted_quantity',
        'status',
        'blocked_reason',
        'blocked_owner_employee_id',
        'self_close_allowed',
        'requires_photo_evidence',
        'min_required_photos',
        'progress_weight',
        'drawing_attachment_id',
        'drawing_revision_id',
        'cancelled_reason',
        'reopened_reason',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'planned_quantity' => 'decimal:2',
            'accepted_quantity' => 'decimal:2',
            'progress_weight' => 'decimal:4',
            'self_close_allowed' => 'boolean',
            'requires_photo_evidence' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<ProjectLocation, $this>
     */
    public function projectLocation(): BelongsTo
    {
        return $this->belongsTo(ProjectLocation::class);
    }

    /**
     * @return BelongsTo<WorkPackage, $this>
     */
    public function workPackage(): BelongsTo
    {
        return $this->belongsTo(WorkPackage::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function accountableOwner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'accountable_owner_employee_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function blockedOwner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'blocked_owner_employee_id');
    }

    /**
     * @return BelongsTo<Attachment, $this>
     */
    public function drawingAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'drawing_attachment_id');
    }

    /**
     * @return BelongsTo<DocumentRevision, $this>
     */
    public function drawingRevision(): BelongsTo
    {
        return $this->belongsTo(DocumentRevision::class, 'drawing_revision_id');
    }

    /**
     * @return HasMany<TaskAssignee, $this>
     */
    public function assignees(): HasMany
    {
        return $this->hasMany(TaskAssignee::class);
    }

    /**
     * @return HasMany<ChecklistItem, $this>
     */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(ChecklistItem::class);
    }

    /**
     * @return HasMany<TaskSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(TaskSubmission::class);
    }

    /**
     * @return HasMany<TaskDependency, $this>
     */
    public function dependencies(): HasMany
    {
        return $this->hasMany(TaskDependency::class);
    }

    /**
     * @return HasMany<TaskStatusEvent, $this>
     */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(TaskStatusEvent::class)->orderBy('occurred_at');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'owner');
    }

    /**
     * @return MorphMany<Comment, $this>
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    protected static function newFactory(): TaskFactory
    {
        return TaskFactory::new();
    }
}
