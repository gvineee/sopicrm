<?php

namespace App\Http\Resources\Tasks;

use App\Domain\Shared\Models\Attachment;
use App\Domain\Tasks\Models\Comment;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Services\TaskQuantityLedger;
use App\Domain\Tasks\Services\TaskReadiness;
use App\Domain\Tasks\Support\EvidenceKind;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full task detail — checklist, assignees, dependencies, submissions,
 * comments, attachments, status history, and the `can` map that drives which
 * workflow buttons the Show page renders (real enforcement is still each
 * route's own Policy check server-side, per the hard constraint).
 *
 * @mixin Task
 */
class TaskDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Task $task */
        $task = $this->resource;

        return [
            'id' => $task->id,
            'project_id' => $task->project_id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'priority' => $task->priority,
            'due_at' => $task->due_at?->toIso8601String(),
            'planned_duration_minutes' => $task->planned_duration_minutes,
            'unit' => $task->unit,
            'planned_quantity' => $task->planned_quantity,
            'accepted_quantity' => $task->accepted_quantity,
            // §17: surfaced so the page can say
            // „ისტორიული ჩანაწერი — ახალი წესით ვერიფიკაცია არ არის
            // დადასტურებული" instead of presenting an old single-person
            // closure as if it met the two-person rule.
            'legacy_acceptance_unverified' => (bool) $task->legacy_acceptance_unverified,
            'remaining_quantity' => app(TaskQuantityLedger::class)->availableScope($task),
            'requires_photo_evidence' => $task->requires_photo_evidence,
            'min_required_photos' => $task->min_required_photos,
            'blocked_reason' => $task->blocked_reason,
            'blocked_owner_employee_id' => $task->blocked_owner_employee_id,
            'cancelled_reason' => $task->cancelled_reason,
            'reopened_reason' => $task->reopened_reason,
            'project_location_id' => $task->project_location_id,
            'work_package_id' => $task->work_package_id,
            'accountable_owner' => $this->whenLoaded('accountableOwner', fn () => $task->accountableOwner === null ? null : [
                'id' => $task->accountableOwner->id,
                'full_name' => trim("{$task->accountableOwner->first_name} {$task->accountableOwner->last_name}"),
            ]),
            'assignees' => $this->whenLoaded('assignees', fn () => $task->assignees->map(fn ($a) => [
                'id' => $a->id,
                'employee_id' => $a->employee_id,
                'employee_name' => $a->relationLoaded('employee') && $a->employee !== null
                    ? trim("{$a->employee->first_name} {$a->employee->last_name}")
                    : null,
                'team_id' => $a->team_id,
                'team_name' => $a->relationLoaded('team') ? $a->team?->name : null,
            ])),
            'checklist_items' => $this->whenLoaded('checklistItems', fn () => $task->checklistItems->map(fn ($c) => [
                'id' => $c->id,
                'label' => $c->label,
                'is_required' => $c->is_required,
                'is_checked' => $c->is_checked,
            ])),
            'dependencies' => $this->whenLoaded('dependencies', fn () => $task->dependencies->map(fn ($d) => [
                'id' => $d->id,
                'depends_on_task_id' => $d->depends_on_task_id,
                'depends_on_title' => $d->relationLoaded('dependsOn') ? $d->dependsOn?->title : null,
                'depends_on_status' => $d->relationLoaded('dependsOn') ? $d->dependsOn?->status : null,
            ])),
            'submissions' => $this->whenLoaded('submissions', fn () => $task->submissions->sortByDesc('submitted_at')->values()->map(fn ($s) => [
                'id' => $s->id,
                'status' => $s->status,
                'submitted_quantity' => $s->submitted_quantity,
                'comment' => $s->comment,
                'photo_attachment_ids' => $s->photo_attachment_ids,
                'submitted_at' => $s->submitted_at?->toIso8601String(),
                'returned_reason' => $s->returned_reason,
                'submitted_by' => $s->relationLoaded('submittedBy') && $s->submittedBy !== null
                    ? trim("{$s->submittedBy->first_name} {$s->submittedBy->last_name}")
                    : null,
                'acceptance' => $s->relationLoaded('acceptance') && $s->acceptance !== null ? [
                    'id' => $s->acceptance->id,
                    'accepted_quantity' => $s->acceptance->accepted_quantity,
                    'accepted_at' => $s->acceptance->accepted_at?->toIso8601String(),
                    'notes' => $s->acceptance->notes,
                ] : null,
                // FILES-01: the submission's own re-owned evidence, with a
                // real preview URL — before this, a reviewer had no way to
                // open what was actually submitted (TaskSubmission::photoAttachments()).
                'photos' => $s->relationLoaded('photoAttachments') ? $s->photoAttachments->map(fn ($a) => $this->attachmentShape($task, $a)) : [],
                'version' => $s->version,
                // TM-02: asked about THIS submission, so a reviewer who
                // performed the work covered by it never sees the buttons —
                // even when they may review other submissions on this task.
                'can' => [
                    'accept' => $s->status === 'pending_review' && $user->can('acceptSubmission', [$task, $s]),
                    'return' => $s->status === 'pending_review' && $user->can('returnSubmission', [$task, $s]),
                ],
            ])),
            'attachments' => $this->whenLoaded('attachments', fn () => $task->attachments->map(fn ($a) => [
                ...$this->attachmentShape($task, $a),
                // TM-03: the submit form needs to know which of these files
                // can be offered as evidence and which ones count toward the
                // photo minimum, so the performer can pick rather than
                // submit an empty evidence list and be refused.
                'is_photo' => EvidenceKind::isPhoto($a),
                'selectable_as_evidence' => $a->status === 'available',
            ])),
            'comments' => $this->whenLoaded('comments', fn () => $task->comments->whereNull('parent_comment_id')->values()->map(fn ($c) => $this->commentShape($c))),
            'status_events' => $this->whenLoaded('statusEvents', fn () => $task->statusEvents->map(fn ($e) => [
                'id' => $e->id,
                'from_status' => $e->from_status,
                'to_status' => $e->to_status,
                'reason' => $e->reason,
                'occurred_at' => $e->occurred_at?->toIso8601String(),
                'actor_name' => $e->relationLoaded('actor') ? $e->actor?->name : null,
            ])),
            'version' => $task->version,
            // §9.1/§9.2: which predecessors are still in the way. Sent so the
            // page can say WHY starting is refused, instead of the person
            // finding out by pressing the button.
            'readiness' => app(TaskReadiness::class)->summaryFor($task),
            'can' => [
                'update' => $user->can('update', $task),
                'assign' => $task->status === 'draft' && $user->can('assign', $task),
                'start' => $task->status === 'assigned' && $user->can('work', $task) && app(TaskReadiness::class)->isReady($task),
                'block' => in_array($task->status, ['assigned', 'in_progress'], true) && $user->can('block', $task),
                'unblock' => $task->status === 'blocked' && $user->can('unblock', $task),
                'submit' => $task->status === 'in_progress' && $user->can('submit', $task),
                'cancel' => ! in_array($task->status, ['cancelled', 'completed'], true) && $user->can('cancel', $task),
                'reopen' => in_array($task->status, ['completed', 'cancelled'], true) && $user->can('reopen', $task),
                'manage_dependencies' => $user->can('manageDependencies', $task),
                'upload_attachment' => $user->can('work', $task),
                'comment' => $user->can('view', $task),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commentShape(Comment $comment): array
    {
        return [
            'id' => $comment->id,
            'body' => $comment->body,
            'author_name' => $comment->relationLoaded('author') ? $comment->author?->name : null,
            'created_at' => $comment->created_at->toIso8601String(),
            'edited_at' => $comment->edited_at?->toIso8601String(),
            'replies' => $comment->relationLoaded('replies') ? $comment->replies->map(fn ($r) => $this->commentShape($r)) : [],
        ];
    }

    /**
     * FILES-01: `url` always points at the protected, policy-checked
     * `projects.tasks.attachments.show` route — never a direct storage
     * path — and is omitted entirely for anything not `available` (a
     * failed/still-scanning upload has nothing safe to open yet).
     *
     * @return array<string, mixed>
     */
    private function attachmentShape(Task $task, Attachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'original_filename' => $attachment->original_filename,
            'mime_type' => $attachment->mime_type,
            'caption' => $attachment->caption,
            'classification' => $attachment->classification,
            'status' => $attachment->status,
            'created_at' => $attachment->created_at?->toIso8601String(),
            'url' => $attachment->status === 'available'
                ? route('projects.tasks.attachments.show', ['project' => $task->project_id, 'task' => $task->id, 'attachment' => $attachment->id])
                : null,
        ];
    }
}
