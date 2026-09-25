<?php

namespace App\Http\Controllers\Tasks;

use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\Team;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Models\Attachment;
use App\Domain\Tasks\Actions\AcceptTaskSubmission;
use App\Domain\Tasks\Actions\AddTaskDependency;
use App\Domain\Tasks\Actions\AssignTask;
use App\Domain\Tasks\Actions\BlockTask;
use App\Domain\Tasks\Actions\CancelTask;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\ReopenTask;
use App\Domain\Tasks\Actions\ReturnTaskSubmission;
use App\Domain\Tasks\Actions\StartTask;
use App\Domain\Tasks\Actions\SubmitTaskForAcceptance;
use App\Domain\Tasks\Actions\ToggleChecklistItem;
use App\Domain\Tasks\Actions\UnblockTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Actions\UploadTaskAttachment;
use App\Domain\Tasks\Models\ChecklistItem;
use App\Domain\Tasks\Models\Comment;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskSubmission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\AcceptTaskSubmissionRequest;
use App\Http\Requests\Tasks\AddTaskDependencyRequest;
use App\Http\Requests\Tasks\BlockTaskRequest;
use App\Http\Requests\Tasks\ReasonRequest;
use App\Http\Requests\Tasks\StoreCommentRequest;
use App\Http\Requests\Tasks\StoreTaskRequest;
use App\Http\Requests\Tasks\SubmitTaskRequest;
use App\Http\Requests\Tasks\ToggleChecklistItemRequest;
use App\Http\Requests\Tasks\UpdateTaskRequest;
use App\Http\Requests\Tasks\UploadTaskAttachmentRequest;
use App\Http\Resources\Employees\EmployeeResource;
use App\Http\Resources\Tasks\TaskDetailResource;
use App\Http\Resources\Tasks\TaskResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * spec section 10 task workflow web UI, nested under a project
 * (routes/modules/web-tasks.php). Thin controller: Policy -> Domain Action ->
 * Inertia response, matching App\Http\Controllers\Projects\ProjectController.
 */
class TaskController extends Controller
{
    use AuthorizesRequests;

    /**
     * Audit A07. A Kanban column that silently stops at a page boundary tells
     * a manager the work is done when it is not, so the board is read whole —
     * but not unboundedly. At this cap the page says the board is truncated
     * and points back at the filters, rather than quietly dropping the rest.
     */
    private const KANBAN_CARD_LIMIT = 300;

    public function index(Request $request, Project $project): Response
    {
        $this->authorize('viewAny', [Task::class, $project]);

        $status = $request->string('status')->trim()->value();
        $ownerId = $request->string('accountable_owner_employee_id')->trim()->value();
        $priority = $request->string('priority')->trim()->value();

        $query = Task::query()->where('project_id', $project->id)->with(['accountableOwner']);

        if ($status) {
            $query->where('status', $status);
        }

        if ($ownerId) {
            $query->where('accountable_owner_employee_id', $ownerId);
        }

        if ($priority) {
            $query->where('priority', $priority);
        }

        $query->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->orderBy('due_at');

        // Audit A07: this screen was list-only while the project page linked
        // to it as „დავალებების სრული სია და Kanban". The board is the same
        // component the dashboard uses (resources/js/components/tasks/TaskKanban.vue),
        // so the two screens cannot disagree about which drag means "start
        // work" and which means "submit for acceptance".
        $view = $request->string('view')->trim()->value();
        $view = in_array($view, ['list', 'kanban'], true) ? $view : 'list';

        if ($view === 'kanban') {
            // A board is read whole, not paged — a column that silently ends
            // at row 30 is worse than no board. It is still bounded, and the
            // page says so when the cap is reached rather than quietly
            // dropping the rest.
            $cards = (clone $query)->limit(self::KANBAN_CARD_LIMIT + 1)->get();
            $truncated = $cards->count() > self::KANBAN_CARD_LIMIT;

            return Inertia::render('Tasks/Index', [
                'project' => ['id' => $project->id, 'name' => $project->name, 'code' => $project->code],
                'view' => 'kanban',
                'tasks' => TaskResource::collection($cards->take(self::KANBAN_CARD_LIMIT)),
                'pagination' => null,
                'kanbanTruncated' => $truncated,
                'kanbanLimit' => self::KANBAN_CARD_LIMIT,
                'filters' => ['status' => $status ?: '', 'accountable_owner_employee_id' => $ownerId ?: '', 'priority' => $priority ?: ''],
                'employees' => EmployeeResource::collection(
                    Employee::query()->where('status', 'active')->orderBy('last_name')->get()
                ),
                'canCreate' => $request->user()->can('create', [Task::class, $project]),
            ]);
        }

        $tasks = $query->paginate(30)->withQueryString();

        return Inertia::render('Tasks/Index', [
            'project' => ['id' => $project->id, 'name' => $project->name, 'code' => $project->code],
            'view' => 'list',
            'tasks' => TaskResource::collection($tasks->items()),
            'kanbanTruncated' => false,
            'kanbanLimit' => self::KANBAN_CARD_LIMIT,
            'pagination' => [
                'page' => $tasks->currentPage(),
                'perPage' => $tasks->perPage(),
                'total' => $tasks->total(),
            ],
            'filters' => ['status' => $status ?: '', 'accountable_owner_employee_id' => $ownerId ?: '', 'priority' => $priority ?: ''],
            'employees' => EmployeeResource::collection(
                Employee::query()->where('status', 'active')->orderBy('last_name')->get()
            ),
            'canCreate' => $request->user()->can('create', [Task::class, $project]),
        ]);
    }

    public function create(Request $request, Project $project): Response
    {
        $this->authorize('create', [Task::class, $project]);

        return Inertia::render('Tasks/Create', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'employees' => EmployeeResource::collection(
                Employee::query()->where('status', 'active')->orderBy('last_name')->get()
            ),
            // Audit A08: brigades were accepted by StoreTaskRequest and
            // handled by CreateTask, but no form ever offered them, so
            // assigning work to a whole crew was unreachable from the UI.
            'teams' => Team::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'existingTasks' => Task::query()->where('project_id', $project->id)->orderBy('title')->get(['id', 'title']),
        ]);
    }

    public function store(StoreTaskRequest $request, Project $project, CreateTask $action): RedirectResponse
    {
        $this->authorize('create', [Task::class, $project]);

        $data = $request->validated();
        $data['project_id'] = $project->id;

        $task = $action->execute($data, $request->user());

        return to_route('projects.tasks.show', [$project, $task])->with('toast', [
            'type' => 'success', 'message' => 'დავალება შეიქმნა.',
        ]);
    }

    public function show(Request $request, Project $project, Task $task): Response
    {
        $this->authorize('view', $task);

        $task->load([
            'accountableOwner', 'assignees.employee', 'assignees.team', 'checklistItems',
            'dependencies.dependsOn', 'submissions.submittedBy', 'submissions.acceptance',
            // FILES-01: a submission's evidence is re-owned away from the
            // Task (see TaskSubmission::photoAttachments()) — without this,
            // a reviewer has no way to see what was actually submitted.
            'submissions.photoAttachments',
            'attachments', 'comments.author', 'comments.replies.author', 'statusEvents.actor',
        ]);

        return Inertia::render('Tasks/Show', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'task' => new TaskDetailResource($task),
        ]);
    }

    /**
     * FILES-01: protected inline preview/download for a task's own
     * attachment OR one of its submissions' re-owned evidence — never a
     * public/guessable path. `abort_unless` re-checks that this specific
     * attachment id actually belongs to THIS task (directly or via one of
     * its own submissions), not merely that some attachment with this id
     * exists somewhere — the hard rule against one module's attachment id
     * being usable against another record.
     */
    public function showAttachment(Project $project, Task $task, Attachment $attachment): StreamedResponse
    {
        $this->authorize('view', $task);
        abort_unless($this->attachmentBelongsToTask($attachment, $task), 404);
        abort_unless($attachment->status === 'available', 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->storage_path), 404);

        return Storage::disk($attachment->disk)->response($attachment->storage_path, $attachment->original_filename);
    }

    private function attachmentBelongsToTask(Attachment $attachment, Task $task): bool
    {
        if ($attachment->owner_type === $task->getMorphClass() && $attachment->owner_id === $task->id) {
            return true;
        }

        if ($attachment->owner_type === TaskSubmission::class) {
            return TaskSubmission::query()
                ->where('id', $attachment->owner_id)
                ->where('task_id', $task->id)
                ->exists();
        }

        return false;
    }

    public function edit(Request $request, Project $project, Task $task): Response
    {
        $this->authorize('update', $task);

        // Audit A08: without these relations TaskDetailResource omits the
        // assignee/checklist/dependency blocks entirely (they are all
        // `whenLoaded`), so the editor would render those sections empty and
        // saving would then delete the very rows it never showed.
        $task->load([
            'accountableOwner',
            'assignees.employee',
            'assignees.team',
            'checklistItems',
            'dependencies.dependsOn',
        ]);

        return Inertia::render('Tasks/Edit', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'task' => new TaskDetailResource($task),
            'employees' => EmployeeResource::collection(
                Employee::query()->where('status', 'active')->orderBy('last_name')->get()
            ),
            'teams' => Team::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            // The task itself is excluded: a dependency on yourself is not a
            // choice worth offering.
            'existingTasks' => Task::query()
                ->where('project_id', $project->id)
                ->whereKeyNot($task->id)
                ->orderBy('title')
                ->get(['id', 'title']),
        ]);
    }

    public function update(UpdateTaskRequest $request, Project $project, Task $task, UpdateTask $action): RedirectResponse
    {
        $this->authorize('update', $task);

        try {
            $action->execute($task, $request->validated(), $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return to_route('projects.tasks.show', [$project, $task])->with('toast', [
            'type' => 'success', 'message' => 'დავალება განახლდა.',
        ]);
    }

    public function assign(Request $request, Project $project, Task $task, AssignTask $action): RedirectResponse
    {
        $this->authorize('assign', $task);

        $action->execute($task, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'დავალება მინიჭებულია.']);
    }

    public function start(Request $request, Project $project, Task $task, StartTask $action): RedirectResponse
    {
        $this->authorize('work', $task);

        $action->execute($task, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'დავალება დაწყებულია.']);
    }

    public function block(BlockTaskRequest $request, Project $project, Task $task, BlockTask $action): RedirectResponse
    {
        $this->authorize('block', $task);

        $action->execute($task, (string) $request->validated('reason'), (string) $request->validated('blocked_owner_employee_id'), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'დავალება დაბლოკილია.']);
    }

    public function unblock(ReasonRequest $request, Project $project, Task $task, UnblockTask $action): RedirectResponse
    {
        $this->authorize('unblock', $task);

        $action->execute($task, $request->user(), $request->validated('reason'));

        return back()->with('toast', ['type' => 'success', 'message' => 'დავალება განბლოკილია.']);
    }

    public function submit(SubmitTaskRequest $request, Project $project, Task $task, SubmitTaskForAcceptance $action): RedirectResponse
    {
        $this->authorize('submit', $task);

        $employee = Employee::query()->where('user_id', $request->user()->id)->first();

        if ($employee === null) {
            return back()->withErrors(['status' => 'თქვენს ანგარიშს არ აქვს დაკავშირებული თანამშრომლის ჩანაწერი.']);
        }

        try {
            $action->execute(
                $task,
                $employee,
                $request->user(),
                $request->validated('comment'),
                $request->validated('submitted_quantity'),
                $request->validated('attachment_ids') ?? [],
                $request->expectedVersion(),
                $request->validated('client_submitted_at'),
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return to_route('projects.tasks.show', [$project, $task])->with('toast', [
            'type' => 'success', 'message' => 'დავალება გაიგზავნა მისაღებად.',
        ]);
    }

    /**
     * 03-Construction-Task-Manager-Spec-KA.md §1: final acceptance takes two
     * different real people. The Policy is asked about THIS submission, not
     * merely about the task, so a reviewer who performed the work covered by
     * it is refused even when they can review other submissions on the same
     * task (TM-02). The parent `project -> task -> submission` chain is
     * already guaranteed by the route group's scoped bindings (TM-07).
     */
    public function acceptSubmission(AcceptTaskSubmissionRequest $request, Project $project, Task $task, TaskSubmission $submission, AcceptTaskSubmission $action): RedirectResponse
    {
        $this->authorize('acceptSubmission', [$task, $submission]);

        try {
            $action->execute(
                $submission,
                $request->user(),
                $request->validated('accepted_quantity'),
                $request->validated('notes'),
                $request->expectedVersion(),
                $request->idempotencyKey(),
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'სამუშაო მიღებულია.']);
    }

    public function returnSubmission(ReasonRequest $request, Project $project, Task $task, TaskSubmission $submission, ReturnTaskSubmission $action): RedirectResponse
    {
        $this->authorize('returnSubmission', [$task, $submission]);

        $reason = $request->validated('reason');
        if (! is_string($reason) || trim($reason) === '') {
            return back()->withErrors(['reason' => 'დაბრუნების მიზეზი სავალდებულოა.']);
        }

        try {
            $action->execute($submission, $request->user(), $reason, $request->expectedVersion(), $request->idempotencyKey());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'დავალება დაბრუნებულია შესასრულებლად.']);
    }

    public function cancel(ReasonRequest $request, Project $project, Task $task, CancelTask $action): RedirectResponse
    {
        $this->authorize('cancel', $task);

        $reason = $request->validated('reason');
        if (! is_string($reason) || trim($reason) === '') {
            return back()->withErrors(['reason' => 'გაუქმების მიზეზი სავალდებულოა.']);
        }

        $action->execute($task, $reason, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'დავალება გაუქმებულია.']);
    }

    public function reopen(ReasonRequest $request, Project $project, Task $task, ReopenTask $action): RedirectResponse
    {
        $this->authorize('reopen', $task);

        $reason = $request->validated('reason');
        if (! is_string($reason) || trim($reason) === '') {
            return back()->withErrors(['reason' => 'ხელახლა გახსნის მიზეზი სავალდებულოა.']);
        }

        $action->execute($task, $reason, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'დავალება ხელახლა გაიხსნა.']);
    }

    public function addDependency(AddTaskDependencyRequest $request, Project $project, Task $task, AddTaskDependency $action): RedirectResponse
    {
        $this->authorize('manageDependencies', $task);

        try {
            $action->execute($task, (string) $request->validated('depends_on_task_id'));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'დამოკიდებულება დამატებულია.']);
    }

    public function uploadAttachment(UploadTaskAttachmentRequest $request, Project $project, Task $task, UploadTaskAttachment $action): RedirectResponse
    {
        $this->authorize('work', $task);

        $gpsConsent = (bool) $request->validated('gps_consent_given');

        try {
            $action->execute(
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
            return back()->withErrors($e->errors());
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'ფაილი აიტვირთა.']);
    }

    public function storeComment(StoreCommentRequest $request, Project $project, Task $task): RedirectResponse
    {
        $this->authorize('view', $task);

        Comment::create([
            'commentable_type' => $task->getMorphClass(),
            'commentable_id' => $task->id,
            'author_user_id' => $request->user()->id,
            'body' => $request->validated('body'),
            'mentions' => $request->validated('mentions') ?? [],
            'parent_comment_id' => $request->validated('parent_comment_id'),
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'კომენტარი დაემატა.']);
    }

    /**
     * TM-09/EV-04: permission alone was not enough. A checklist that can
     * still be edited while a reviewer is looking at the submission — or
     * after the task is finished — means the answers on screen are not the
     * answers that were submitted. The submitted answers are frozen in
     * `task_submissions.checklist_snapshot`; this guard stops the live rows
     * from drifting underneath a review or a closed task.
     */
    public function toggleChecklistItem(ToggleChecklistItemRequest $request, Project $project, Task $task, ChecklistItem $checklistItem, ToggleChecklistItem $action): RedirectResponse
    {
        $this->authorize('work', $task);

        abort_unless($checklistItem->task_id === $task->id, 404);

        if (! in_array($task->status, ['draft', 'assigned', 'in_progress'], true)) {
            return back()->withErrors([
                'checklist' => $task->status === 'submitted'
                    ? 'დავალება განხილვაშია — checklist-ის შეცვლა შეუძლებელია, სანამ შემმოწმებელი გადაწყვეტილებას მიიღებს.'
                    : 'დასრულებულ/გაუქმებულ დავალებაზე checklist-ის შეცვლა შეუძლებელია.',
            ]);
        }

        // This used to duplicate the Action's body inline, which left
        // App\Domain\Tasks\Actions\ToggleChecklistItem as dead code and meant
        // the write bypassed the domain layer entirely — so when the Tasks
        // domain gained an audit trail (DV-01), this one path silently kept
        // writing nothing.
        $action->execute($checklistItem, (bool) $request->validated('is_checked'), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Checklist განახლდა.']);
    }
}
