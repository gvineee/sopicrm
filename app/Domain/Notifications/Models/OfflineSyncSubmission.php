<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Domain\Shared\Models\Attachment;
use App\Domain\Tasks\Models\Comment;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskSubmission;
use App\Models\User;
use Database\Factories\OfflineSyncSubmissionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/decisions.md (Notifications/PWA module) — the durable outcome record
 * for every offline comment/photo/task-close-out replay. See the migration's
 * own docblock for the full rationale. `status='for_review'` rows are the
 * real, DB-backed "გასარჩევი" (for-review) queue spec section 17 requires
 * instead of a silent accept or a silent drop.
 */
class OfflineSyncSubmission extends Model
{
    /** @use HasFactory<OfflineSyncSubmissionFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'user_id',
        'employee_id',
        'kind',
        'task_id',
        'client_item_id',
        'status',
        'payload',
        'reason',
        'resulting_comment_id',
        'resulting_task_submission_id',
        'resulting_attachment_id',
        'client_created_at',
        'reviewed_at',
        'reviewed_by_user_id',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'client_created_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<OfflineSyncSubmission>  $query
     * @return Builder<OfflineSyncSubmission>
     */
    public function scopeForReview(Builder $query): Builder
    {
        return $query->where('status', 'for_review');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<Comment, $this>
     */
    public function resultingComment(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'resulting_comment_id');
    }

    /**
     * @return BelongsTo<TaskSubmission, $this>
     */
    public function resultingTaskSubmission(): BelongsTo
    {
        return $this->belongsTo(TaskSubmission::class, 'resulting_task_submission_id');
    }

    /**
     * @return BelongsTo<Attachment, $this>
     */
    public function resultingAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'resulting_attachment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    protected static function newFactory(): OfflineSyncSubmissionFactory
    {
        return OfflineSyncSubmissionFactory::new();
    }
}
