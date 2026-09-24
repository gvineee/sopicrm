<?php

namespace App\Http\Controllers\Api\V1\Tasks;

use App\Domain\Employees\Models\Employee;
use App\Domain\Notifications\Models\OfflineSyncSubmission;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Actions\SubmitTaskForAcceptance;
use App\Domain\Tasks\Actions\UploadTaskAttachment;
use App\Domain\Tasks\Models\Task;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\SubmitTaskRequest;
use App\Http\Requests\Tasks\UploadTaskAttachmentRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * PWA-01: the /api/v1 replay target for resources/js/lib/offlineQueue.ts's
 * queued 'task-photo'/'task-submission' items (spec section 18: "Inertia
 * ჩვეულებრივი გვერდებისთვის; /api/v1 ... მობილური offline queue-ის ...
 * საჭიროებისთვის" — the same split DailyJournal's api-dailyjournal.php
 * already established, copied here rather than reinvented).
 *
 * Deliberately NOT the same routes TaskController's Inertia actions use:
 * those return a redirect and have no Idempotency-Key requirement, so every
 * existing desktop caller would break if `idempotency` middleware were
 * added to them directly. These are new, JSON-only endpoints that call the
 * exact same Domain Actions and additionally write the durable
 * OfflineSyncSubmission outcome row the ticket's own schema already
 * anticipates (see that model/migration's docblocks) — never a duplicate of
 * the business logic, only a second, replay-shaped transport for it.
 *
 * Every call writes exactly one OfflineSyncSubmission row, whatever the
 * outcome, per its own docblock ("the durable outcome record for every
 * offline comment/photo/task-close-out replay"):
 *  - `applied` — the action went through exactly as it would from the
 *    normal desktop flow.
 *  - `conflict` — the task's own state (or the actor's authorization to act
 *    on it) no longer matches what the client expected when it queued this
 *    offline — e.g. reassigned away, already submitted by someone else.
 *    Never silently retried; the client-side queue marks it 'conflict' too
 *    and stops auto-retrying.
 *  - `for_review` — a validation failure that isn't simply "state changed"
 *    (e.g. a required-photo-count rule) — genuinely needs a human to look,
 *    not an automatic retry.
 *  - `rejected` — the action is now definitively invalid (no linked
 *    Employee record for a submit attempt).
 */
class OfflineSyncController extends Controller
{
    public function storeAttachment(
        UploadTaskAttachmentRequest $request,
        Project $project,
        Task $task,
        UploadTaskAttachment $action,
    ): JsonResponse {
        $this->authorize('work', $task);

        $clientItemId = $this->clientItemId($request);

        $gpsConsent = (bool) $request->validated('gps_consent_given');

        try {
            $attachment = $action->execute(
                $task,
                $request->file('file'),
                $request->user(),
                $request->validated('classification'),
                $request->validated('caption'),
                $request->validated('taken_at_client_claimed'),
                $gpsConsent ? $request->validated('gps_latitude') : null,
                $gpsConsent ? $request->validated('gps_longitude') : null,
                $gpsConsent,
            );
        } catch (ValidationException $e) {
            $this->recordSync(
                kind: 'photo',
                task: $task,
                request: $request,
                clientItemId: $clientItemId,
                payload: $request->except('file'),
                status: 'for_review',
                reason: $this->firstMessage($e),
            );

            return response()->json(['status' => 'for_review', 'errors' => $e->errors()], 422);
        }

        $this->recordSync(
            kind: 'photo',
            task: $task,
            request: $request,
            clientItemId: $clientItemId,
            payload: $request->except('file'),
            status: 'applied',
            resultingAttachmentId: $attachment->id,
        );

        return response()->json(['status' => 'applied', 'attachment_id' => $attachment->id], 201);
    }

    public function storeSubmission(
        SubmitTaskRequest $request,
        Project $project,
        Task $task,
        SubmitTaskForAcceptance $action,
    ): JsonResponse {
        $this->authorize('submit', $task);

        $clientItemId = $this->clientItemId($request);
        $employee = Employee::query()->where('user_id', $request->user()->id)->first();

        if ($employee === null) {
            $this->recordSync(
                kind: 'task_submission',
                task: $task,
                request: $request,
                clientItemId: $clientItemId,
                payload: $request->all(),
                status: 'rejected',
                reason: 'ანგარიშს არ აქვს დაკავშირებული თანამშრომლის ჩანაწერი.',
            );

            return response()->json(['status' => 'rejected'], 422);
        }

        try {
            $submission = $action->execute(
                $task,
                $employee,
                $request->user(),
                $request->validated('comment'),
                $request->validated('submitted_quantity'),
                $request->validated('attachment_ids') ?? [],
                $request->expectedVersion(),
                // The device's own capture time, kept apart from the
                // server's `submitted_at` and never substituted for it
                // (§13.2). A queued item can be days old; that is exactly
                // why both times are worth having.
                $request->validated('client_submitted_at') ?? $request->string('client_created_at')->value() ?: null,
            );
        } catch (ValidationException $e) {
            // A `status` field error means the task's own state no longer
            // allows a submit (e.g. already submitted/accepted/cancelled
            // since this item was queued offline) — a real conflict, not a
            // fixable validation mistake. Anything else (missing required
            // photos, an out-of-range quantity) is a for_review case: a
            // human needs to look, but nothing about the task itself
            // changed underneath the worker.
            $status = array_key_exists('status', $e->errors()) ? 'conflict' : 'for_review';

            $this->recordSync(
                kind: 'task_submission',
                task: $task,
                request: $request,
                clientItemId: $clientItemId,
                payload: $request->all(),
                status: $status,
                reason: $this->firstMessage($e),
            );

            return response()->json(['status' => $status, 'errors' => $e->errors()], $status === 'conflict' ? 409 : 422);
        }

        $this->recordSync(
            kind: 'task_submission',
            task: $task,
            request: $request,
            clientItemId: $clientItemId,
            payload: $request->all(),
            status: 'applied',
            resultingTaskSubmissionId: $submission->id,
        );

        return response()->json(['status' => 'applied', 'submission_id' => $submission->id], 201);
    }

    private function clientItemId(Request $request): ?string
    {
        $value = $request->input('client_item_id');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordSync(
        string $kind,
        Task $task,
        Request $request,
        ?string $clientItemId,
        array $payload,
        string $status,
        ?string $reason = null,
        ?string $resultingAttachmentId = null,
        ?string $resultingTaskSubmissionId = null,
    ): void {
        /** @var User $user */
        $user = $request->user();
        $employee = Employee::query()->where('user_id', $user->id)->first();

        OfflineSyncSubmission::query()->create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'employee_id' => $employee?->id,
            'kind' => $kind,
            'task_id' => $task->id,
            'client_item_id' => $clientItemId,
            'status' => $status,
            'payload' => $payload,
            'reason' => $reason,
            'resulting_attachment_id' => $resultingAttachmentId,
            'resulting_task_submission_id' => $resultingTaskSubmissionId,
            'client_created_at' => $request->input('client_created_at'),
        ]);
    }

    private function firstMessage(ValidationException $e): string
    {
        $first = collect($e->errors())->flatten()->first();

        return is_string($first) ? $first : $e->getMessage();
    }
}
