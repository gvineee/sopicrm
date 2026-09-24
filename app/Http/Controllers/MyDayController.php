<?php

namespace App\Http\Controllers;

use App\Domain\Employees\Models\Employee;
use App\Domain\Notifications\Models\OfflineSyncSubmission;
use App\Domain\Shared\Models\Attachment;
use App\Domain\Tasks\Models\Task;
use App\Policies\TaskPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * WORKER-01: real "ჩემი დღე" (My Day) data — replaces the static
 * `demoTasks` placeholder resources/js/pages/MyDay.vue previously rendered
 * (docs/decisions.md DEC-050). Reuses App\Policies\TaskPolicy::scopeVisibleToPerformer()
 * (the exact same "am I actually the performer of this task" rule
 * DashboardController and TaskPolicy::view() already enforce — FIX-02/A3)
 * rather than inventing a second definition of "my tasks." No new mobile
 * business logic: every action this page offers (start/comment/photo/
 * submit/block) posts straight to the existing project-nested Tasks module
 * routes/actions — this controller only assembles the read side.
 */
class MyDayController extends Controller
{
    public function index(Request $request, TaskPolicy $taskPolicy): Response
    {
        $user = $request->user();
        $employee = Employee::query()->where('user_id', $user->id)->first();

        if ($employee === null) {
            return Inertia::render('MyDay', [
                'hasEmployeeRecord' => false,
                // Audit A11: whether the person reading this dead end is able
                // to resolve it themselves. Administrators usually are, and
                // used to be told to ask someone else.
                'canLinkAccounts' => $user->can('employees.invites.manage'),
                'today' => [],
                'overdue' => [],
                'inReview' => [],
                'returned' => [],
                'employees' => [],
                'organizationId' => $user->organization_id,
                'userId' => $user->id,
                'offlineReviewItems' => [],
            ]);
        }

        $base = Task::query();
        $taskPolicy->scopeVisibleToPerformer($base, $user);

        $today = Carbon::today();

        $tasks = (clone $base)
            ->whereNotIn('status', ['draft', 'completed', 'cancelled'])
            ->with([
                'project:id,name',
                // Evidence still owned by the Task itself (not yet moved to
                // a submission) — exactly what SubmitTaskForAcceptance
                // accepts as `attachment_ids`.
                'attachments' => fn ($query) => $query->where('status', 'available'),
                'submissions' => fn ($query) => $query->orderByDesc('submitted_at')->limit(1),
            ])
            ->orderBy('due_at')
            ->get();

        // The spec names exactly 4 buckets, no separate "upcoming/future"
        // one — an active, non-overdue, non-returned, non-in-review task
        // goes to "today" regardless of how far its own due date is, since
        // that is this worker's actionable backlog right now, not a
        // calendar of future dates.
        $buckets = ['today' => [], 'overdue' => [], 'inReview' => [], 'returned' => []];

        foreach ($tasks as $task) {
            $card = $this->cardFor($task);
            $latestSubmission = $task->submissions->first();

            if ($task->status === 'submitted') {
                $buckets['inReview'][] = $card;

                continue;
            }

            if ($task->status === 'in_progress' && $latestSubmission?->status === 'returned') {
                $buckets['returned'][] = [...$card, 'returnedReason' => $latestSubmission->returned_reason];

                continue;
            }

            if ($task->due_at !== null && $task->due_at->lt($today)) {
                $buckets['overdue'][] = $card;

                continue;
            }

            $buckets['today'][] = $card;
        }

        return Inertia::render('MyDay', [
            'hasEmployeeRecord' => true,
            'canLinkAccounts' => $user->can('employees.invites.manage'),
            ...$buckets,
            'employees' => Employee::query()
                ->where('organization_id', $user->organization_id)
                ->where('status', 'active')
                ->where('id', '!=', $employee->id)
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name']),
            'organizationId' => $user->organization_id,
            'userId' => $user->id,
            // PWA-01: rows a previous offline replay could not silently
            // resolve — surfaced here (not a separate admin queue, out of
            // this ticket's real scope) so the same worker who submitted
            // them offline sees what needs their attention, rather than a
            // human never finding out an offline action didn't cleanly
            // apply.
            'offlineReviewItems' => OfflineSyncSubmission::query()
                ->forReview()
                ->where('user_id', $user->id)
                ->whereNull('reviewed_at')
                ->orderByDesc('created_at')
                ->limit(20)
                ->get(['id', 'kind', 'task_id', 'reason', 'created_at'])
                ->map(fn (OfflineSyncSubmission $item) => [
                    'id' => $item->id,
                    'kind' => $item->kind,
                    'taskId' => $item->task_id,
                    'reason' => $item->reason,
                    'createdAt' => $item->created_at?->toIso8601String(),
                ]),
        ]);
    }

    /**
     * PWA-01: acknowledges (dismisses) a `for_review` OfflineSyncSubmission
     * row — records who/when, never mutates the original Task/attachment
     * state, and never reprocesses the offline action itself (a manager
     * following up on the `reason` text is a separate, out-of-scope
     * workflow this ticket does not build).
     */
    public function acknowledgeOfflineReview(Request $request, OfflineSyncSubmission $submission): RedirectResponse
    {
        abort_unless($submission->user_id === $request->user()->id, 403);

        $submission->update([
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $request->user()->id,
        ]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function cardFor(Task $task): array
    {
        return [
            'id' => $task->id,
            'projectId' => $task->project_id,
            'title' => $task->title,
            'projectName' => $task->project?->name,
            'dueAt' => $task->due_at?->toIso8601String(),
            'status' => $task->status,
            'requiresPhotoEvidence' => $task->requires_photo_evidence,
            'minRequiredPhotos' => $task->min_required_photos,
            'attachments' => $task->attachments->map(fn (Attachment $attachment) => [
                'id' => $attachment->id,
                'caption' => $attachment->caption,
            ])->values(),
        ];
    }
}
