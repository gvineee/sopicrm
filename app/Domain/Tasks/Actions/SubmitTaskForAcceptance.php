<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Models\Attachment;
use App\Domain\Tasks\Models\ChecklistItem;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskSubmission;
use App\Domain\Tasks\Services\ReviewerIndependence;
use App\Domain\Tasks\Services\TaskQuantityLedger;
use App\Domain\Tasks\Services\TaskStatusEventRecorder;
use App\Domain\Tasks\Support\Decimal;
use App\Domain\Tasks\Support\EvidenceKind;
use App\Models\User;
use Illuminate\Support\Carbon;
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
 *
 * 03-Construction-Task-Manager-Spec-KA.md changes on top of that:
 *
 *  - TM-04: the minimum-evidence rule counts PHOTOS, by real MIME type. A
 *    PDF no longer silently satisfies „საჭიროა მინიმუმ N ფოტო".
 *  - TM-06: `submittedQuantity` is a DELTA — the volume done THIS time —
 *    checked against the scope still available (§8), not against the whole
 *    plan. Submitting 40 twice on a 100 m² task is legal; submitting 60 when
 *    only 50 remains unclaimed is not.
 *  - TM-02/TM-09/§13.2: the submission freezes what it is asking a reviewer
 *    to judge — who participated, which checklist answers were given, which
 *    evidence was offered and what type each file really is, plus the task
 *    version at that instant. Freezing participation at submission time is
 *    what stops a later reassignment from retroactively making a performer
 *    look like an independent reviewer.
 *  - TM-08/§13.2: state is re-read under a row lock inside the transaction,
 *    so two devices replaying the same queued submission cannot both pass
 *    the "is it still in_progress" check.
 */
class SubmitTaskForAcceptance
{
    public function __construct(
        private readonly TaskStatusEventRecorder $recorder,
        private readonly TaskQuantityLedger $ledger,
        private readonly ReviewerIndependence $independence,
    ) {}

    /**
     * @param  list<string>  $attachmentIds  IDs of Attachment rows already
     *                                       uploaded and owned by this Task
     *                                       (see UploadTaskAttachment) that
     *                                       the submitter is offering as
     *                                       evidence for this submission.
     * @param  string|null  $clientSubmittedAt  Device-claimed capture time,
     *                                          recorded but never trusted;
     *                                          `submitted_at` is the server's.
     */
    public function execute(
        Task $task,
        Employee $submittingEmployee,
        User $actor,
        ?string $comment,
        ?string $submittedQuantity,
        array $attachmentIds,
        ?int $expectedVersion = null,
        ?string $clientSubmittedAt = null,
    ): TaskSubmission {
        if ($submittedQuantity !== null && ! is_numeric($submittedQuantity)) {
            throw ValidationException::withMessages([
                'submitted_quantity' => 'შესრულებული მოცულობა უნდა იყოს რიცხვი.',
            ]);
        }

        return DB::transaction(function () use (
            $task, $submittingEmployee, $actor, $comment, $submittedQuantity,
            $attachmentIds, $expectedVersion, $clientSubmittedAt,
        ) {
            // §13.2: re-read the target under a lock and judge the CURRENT
            // row, never the copy the caller happened to load earlier.
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);

            if ($expectedVersion !== null && (int) $locked->version !== $expectedVersion) {
                throw ValidationException::withMessages([
                    'status' => 'დავალება შეიცვალა სხვისი მოქმედებით. გადატვირთეთ გვერდი და სცადეთ ხელახლა.',
                ]);
            }

            if ($locked->status !== 'in_progress') {
                throw ValidationException::withMessages([
                    'status' => 'დავალების დასრულებაზე გაგზავნა შესაძლებელია მხოლოდ in_progress სტატუსიდან.',
                ]);
            }

            if (TaskSubmission::query()->where('task_id', $locked->id)->where('status', 'pending_review')->exists()) {
                throw ValidationException::withMessages([
                    'status' => 'ამ დავალებას უკვე აქვს განსახილველი გაგზავნა. დაელოდეთ შემმოწმებლის გადაწყვეტილებას.',
                ]);
            }

            $checklistItems = ChecklistItem::query()->where('task_id', $locked->id)->orderBy('created_at')->get();

            if ($checklistItems->where('is_required', true)->where('is_checked', false)->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'checklist' => 'ყველა სავალდებულო checklist პუნქტი უნდა იყოს მონიშნული დასრულებამდე.',
                ]);
            }

            $attachments = Attachment::query()
                ->where('owner_type', Task::class)
                ->where('owner_id', $locked->id)
                ->whereIn('id', $attachmentIds)
                ->get();

            if ($attachments->count() !== count(array_unique($attachmentIds))) {
                throw ValidationException::withMessages([
                    'attachments' => 'ერთი ან მეტი მითითებული ფაილი ვერ მოიძებნა ამ დავალებაზე.',
                ]);
            }

            if ($attachments->firstWhere('status', '!=', 'available') !== null) {
                // Hard rule: a failed/still-uploading required photo must never
                // let the task reach "submitted" — thrown before any write.
                throw ValidationException::withMessages([
                    'attachments' => 'ერთი ან მეტი ფოტო/ფაილი ჯერ არ არის სრულად ატვირთული ან ატვირთვა ჩაიშალა. სცადეთ ხელახლა ატვირთვა.',
                ]);
            }

            $photoCount = $attachments->filter(fn (Attachment $a) => EvidenceKind::isPhoto($a))->count();

            if ($locked->requires_photo_evidence && $photoCount < (int) $locked->min_required_photos) {
                throw ValidationException::withMessages([
                    'attachments' => "ამ დავალების დასასრულებლად საჭიროა მინიმუმ {$locked->min_required_photos} ფოტო მტკიცებულებად (PDF/დოკუმენტი ფოტოს ვერ ჩაანაცვლებს).",
                ]);
            }

            $this->assertQuantityWithinAvailableScope($locked, $submittedQuantity);

            $submission = TaskSubmission::create([
                'task_id' => $locked->id,
                'submitted_by_employee_id' => $submittingEmployee->id,
                'submitted_by_user_id' => $actor->id,
                'submitted_quantity' => $submittedQuantity,
                'comment' => $comment,
                'photo_attachment_ids' => $attachments->pluck('id')->values()->all(),
                'participant_snapshot' => $this->independence->snapshotFor($locked, $submittingEmployee, $actor->id),
                'checklist_snapshot' => $checklistItems->map(fn (ChecklistItem $item) => [
                    'id' => $item->id,
                    'label' => $item->label,
                    'is_required' => (bool) $item->is_required,
                    'is_checked' => (bool) $item->is_checked,
                    'checked_by_user_id' => $item->checked_by_user_id,
                    'checked_at' => $item->checked_at?->toIso8601String(),
                ])->values()->all(),
                'evidence_snapshot' => $attachments->map(fn (Attachment $a) => [
                    'id' => $a->id,
                    'mime_type' => $a->mime_type,
                    'classification' => $a->classification,
                    'checksum' => $a->checksum,
                    'is_photo' => EvidenceKind::isPhoto($a),
                ])->values()->all(),
                'task_version_at_submission' => (int) $locked->version,
                'submitted_at' => now(),
                'client_submitted_at' => $this->parseClientTime($clientSubmittedAt),
                'status' => 'pending_review',
            ]);

            // Re-own the evidence to the finalized submission (still the
            // same rows — upload timestamp, the trusted time per spec 10,
            // is untouched) so future queries resolve "whose evidence is
            // this" to the submission it was actually judged against.
            Attachment::query()
                ->whereIn('id', $attachments->pluck('id'))
                ->update(['owner_type' => TaskSubmission::class, 'owner_id' => $submission->id]);

            $from = $locked->status;
            $locked->update(['status' => 'submitted']);
            $this->recorder->record($locked, $from, 'submitted', $actor);

            $task->setRawAttributes($locked->getAttributes(), true);

            return $submission->fresh();
        });
    }

    /**
     * §8's first invariant: `0 < newly_submitted_quantity <= currently_available_scope`.
     * The ceiling is what is still unclaimed — plan minus what has already
     * been netted into the ledger minus what another submission is already
     * waiting on — not the whole plan, which is what made the old cumulative
     * reading reject a perfectly legal second delta (TM-06).
     */
    private function assertQuantityWithinAvailableScope(Task $task, ?string $submittedQuantity): void
    {
        if ($submittedQuantity === null) {
            return;
        }

        $quantity = Decimal::of($submittedQuantity);

        if (bccomp($quantity, '0', 2) !== 1) {
            throw ValidationException::withMessages([
                'submitted_quantity' => 'შესრულებული მოცულობა ნულზე მეტი უნდა იყოს.',
            ]);
        }

        $available = $this->ledger->availableScope($task);

        if ($available !== null && bccomp($quantity, Decimal::of($available), 2) === 1) {
            throw ValidationException::withMessages([
                'submitted_quantity' => "შესრულებული მოცულობა აღემატება დარჩენილ მოცულობას ({$available} {$task->unit}).",
            ]);
        }
    }

    private function parseClientTime(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            // A device with a broken clock or locale must not block a real
            // submission — the server's own `submitted_at` is authoritative.
            return null;
        }
    }
}
