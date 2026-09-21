<?php

namespace App\Domain\Tasks\Models;

use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Domain\Shared\Models\Attachment;
use Database\Factories\TaskSubmissionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * docs/data-model.md "task_submissions" (spec section 10 hard rule): if
 * required photos fail to upload, the submission must NOT transition beyond
 * a recoverable draft — enforced at the Domain Action level (only finalized
 * once all required attachments report `status='available'`), not by this
 * model directly. Only the accountable owner or an authorized reviewer can
 * accept/return a submission — Policy-enforced.
 */
/**
 * @property numeric-string|null $submitted_quantity
 * @property Carbon|null $submitted_at
 */
class TaskSubmission extends Model
{
    /** @use HasFactory<TaskSubmissionFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'task_id',
        'submitted_by_employee_id',
        'submitted_quantity',
        'comment',
        'photo_attachment_ids',
        'submitted_at',
        'status',
        'returned_reason',
    ];

    protected function casts(): array
    {
        return [
            'submitted_quantity' => 'decimal:2',
            'photo_attachment_ids' => 'array',
            'submitted_at' => 'datetime',
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
     * @return BelongsTo<Employee, $this>
     */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'submitted_by_employee_id');
    }

    /**
     * @return HasOne<TaskAcceptance, $this>
     */
    public function acceptance(): HasOne
    {
        return $this->hasOne(TaskAcceptance::class);
    }

    /**
     * FILES-01: `SubmitTaskForAcceptance` re-owns each evidence Attachment
     * from the Task to this submission (`owner_type`/`owner_id`) at
     * submission time — this is the live, authoritative resolution of
     * `photo_attachment_ids` (which stays only as a historical record of
     * what was offered), used so a reviewer can actually open the evidence
     * before accepting/returning.
     *
     * @return MorphMany<Attachment, $this>
     */
    public function photoAttachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'owner');
    }

    protected static function newFactory(): TaskSubmissionFactory
    {
        return TaskSubmissionFactory::new();
    }
}
