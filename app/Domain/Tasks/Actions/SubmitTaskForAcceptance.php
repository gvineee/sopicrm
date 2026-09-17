<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Models\Attachment;
use App\Domain\Tasks\Models\ChecklistItem;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskSubmission;
use App\Domain\Tasks\Services\TaskStatusEventRecorder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Task state machine (spec section 10): in_progress -> submitted. This is
 * the employee's "დავასრულე" (mark done) action.
 *
 * Hard rule (spec section 10 + section 23's mandatory test row "task-ის
 * დახურვას ფოტო აკლია → ვალიდაციის შეცდომა, draft შენარჩუნებულია"): every
 * validation below runs and throws BEFORE any row is written or any
 * attachment is re-owned. If validation fails, the task stays `in_progress`
 * exactly as it was and every already-uploaded attachment stays owned by
 * the Task (still visible, still retryable) — nothing is left half-done.
 */
class SubmitTaskForAcceptance
{
    public function __construct(private readonly TaskStatusEventRecorder $recorder) {}

    /**
     * @param  list<string>  $attachmentIds  IDs of Attachment rows already
     *                                       uploaded and owned by this Task
     *                                       (see UploadTaskAttachment) that
     *                                       the submitter is offering as
     *                                       evidence for this submission.
     */
    public function execute(
        Task $task,
        Employee $submittingEmployee,
        User $actor,
        ?string $comment,
        ?string $submittedQuantity,
        array $attachmentIds,
    ): TaskSubmission {
        if ($task->status !== 'in_progress') {
            throw ValidationException::withMessages([
                'status' => 'დავალების დასრულებაზე გაგზავნა შესაძლებელია მხოლოდ in_progress სტატუსიდან.',
            ]);
        }

        $missingRequiredChecklist = ChecklistItem::query()
            ->where('task_id', $task->id)
            ->where('is_required', true)
            ->where('is_checked', false)
            ->exists();

        if ($missingRequiredChecklist) {
            throw ValidationException::withMessages([
                'checklist' => 'ყველა სავალდებულო checklist პუნქტი უნდა იყოს მონიშნული დასრულებამდე.',
            ]);
        }

        $attachments = Attachment::query()
            ->where('owner_type', Task::class)
            ->where('owner_id', $task->id)
            ->whereIn('id', $attachmentIds)
            ->get();

        if ($attachments->count() !== count(array_unique($attachmentIds))) {
            throw ValidationException::withMessages([
                'attachments' => 'ერთი ან მეტი მითითებული ფაილი ვერ მოიძებნა ამ დავალებაზე.',
            ]);
        }

        $notAvailable = $attachments->firstWhere('status', '!=', 'available');
        if ($notAvailable !== null) {
            // Hard rule: a failed/still-uploading required photo must never
            // let the task reach "submitted" — thrown before any write.
            throw ValidationException::withMessages([
                'attachments' => 'ერთი ან მეტი ფოტო/ფაილი ჯერ არ არის სრულად ატვირთული ან ატვირთვა ჩაიშალა. სცადეთ ხელახლა ატვირთვა.',
            ]);
        }

        if ($task->requires_photo_evidence && $attachments->count() < $task->min_required_photos) {
            throw ValidationException::withMessages([
                'attachments' => "ამ დავალების დასასრულებლად საჭიროა მინიმუმ {$task->min_required_photos} ფოტო/ფაილი მტკიცებულებად.",
            ]);
        }

        if ($submittedQuantity !== null && ! is_numeric($submittedQuantity)) {
            throw ValidationException::withMessages([
                'submitted_quantity' => 'Submitted quantity must be a valid decimal number.',
            ]);
        }

        if ($task->planned_quantity !== null && $submittedQuantity !== null && bccomp($submittedQuantity, $task->planned_quantity, 2) === 1) {
            throw ValidationException::withMessages([
                'submitted_quantity' => 'შესრულებული მოცულობა ვერ აღემატება დაგეგმილ მოცულობას.',
            ]);
        }

        return DB::transaction(function () use ($task, $submittingEmployee, $actor, $comment, $submittedQuantity, $attachments) {
            $submission = TaskSubmission::create([
                'task_id' => $task->id,
                'submitted_by_employee_id' => $submittingEmployee->id,
                'submitted_quantity' => $submittedQuantity,
                'comment' => $comment,
                'photo_attachment_ids' => $attachments->pluck('id')->values()->all(),
                'submitted_at' => now(),
                'status' => 'pending_review',
            ]);

            // Re-own the evidence to the finalized submission (still the
            // same rows — upload timestamp, the trusted time per spec 10,
            // is untouched) so future queries resolve "whose evidence is
            // this" to the submission it was actually judged against.
            Attachment::query()
                ->whereIn('id', $attachments->pluck('id'))
                ->update(['owner_type' => TaskSubmission::class, 'owner_id' => $submission->id]);

            $from = $task->status;
            $task->update(['status' => 'submitted']);
            $this->recorder->record($task, $from, 'submitted', $actor);

            return $submission->fresh();
        });
    }
}
