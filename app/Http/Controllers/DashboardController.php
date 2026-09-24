<?php

namespace App\Http\Controllers;

use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use App\Policies\TaskPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Project Manager dashboard — replaces the P0 visual-foundation placeholder
 * (see resources/js/pages/Dashboard.vue's own former docblock) with real
 * Projects/Tasks data. Visibility follows the exact same rule as
 * App\Http\Controllers\Projects\ProjectController::index(): an owner sees
 * every project in the organization, everyone else only the projects they
 * are an active member of (spec section 3: project membership + role
 * jointly decide access) — this dashboard never shows a KPI or row the user
 * could not otherwise reach directly. Tasks are further narrowed by
 * App\Policies\TaskPolicy::scopeVisibleToPerformer() for anyone without
 * project-wide `tasks.tasks.view`, so a plain team member's KPIs/list only
 * ever reflect tasks they could actually open (see docs/claude-platform-completion-2026-09-21.md
 * FIX-02/A3).
 *
 * Only KPIs backed by data this session can actually compute are shown
 * (projects/tasks) — no placeholder attendance/tool numbers are fabricated
 * here; those modules get their own dashboard tiles once they exist.
 */
class DashboardController extends Controller
{
    public const FILTER_OPEN = 'open';

    public const FILTER_OVERDUE = 'overdue';

    public const FILTER_COMPLETED_30D = 'completed_30d';

    /** Georgian headings for the drill-down list, keyed by filter. */
    private const FILTER_TITLES = [
        self::FILTER_OPEN => 'ღია დავალებები',
        self::FILTER_OVERDUE => 'ვადაგადაცილებული დავალებები',
        self::FILTER_COMPLETED_30D => 'დასრულებული დავალებები (30 დღე)',
    ];

    public function index(Request $request, TaskPolicy $taskPolicy): Response
    {
        /** @var User $user */
        $user = $request->user();

        $projectsQuery = Project::query();
        if (! $user->can('viewAny', Project::class)) {
            $projectsQuery->whereHas('memberships', function ($q) use ($user) {
                $q->where('user_id', $user->id)->whereNull('removed_at');
            });
        }
        $activeProjectsCount = (clone $projectsQuery)->where('status', 'active')->count();

        // Being able to see a project (membership) is not the same as being
        // able to see every task inside it (audit finding FIX-02/A3,
        // 2026-09-21) — a user without project-wide `tasks.tasks.view` only
        // ever sees tasks they are the performer of, matching
        // TaskPolicy::view()'s own rule exactly (see
        // TaskPolicy::scopeVisibleToPerformer()).
        $taskBase = $this->visibleTaskQuery($user, $taskPolicy);

        // Audit A15 / acceptance NAV-02: each KPI below and the drill-down
        // list behind its card are produced by the SAME filter helper, so a
        // card's number and the list it opens cannot drift apart.
        $openTasksCount = $this->applyTaskFilter((clone $taskBase), self::FILTER_OPEN)->count();
        $overdueTasksCount = $this->applyTaskFilter((clone $taskBase), self::FILTER_OVERDUE)->count();
        $completedLast30DaysCount = $this->applyTaskFilter((clone $taskBase), self::FILTER_COMPLETED_30D)->count();

        $projects = $projectsQuery->with(['client', 'manager'])
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get()
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'code' => $project->code,
                'manager' => $project->manager?->name,
                'status' => $project->status,
                'due_date' => $project->ends_on?->toDateString(),
            ]);

        $tasks = (clone $taskBase)
            ->whereNotIn('status', ['cancelled'])
            ->with('project')
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get()
            ->map(fn (Task $task) => [
                'id' => $task->id,
                'project_id' => $task->project_id,
                'title' => $task->title,
                'project_name' => $task->project?->name,
                'status' => $task->status,
            ]);

        return Inertia::render('Dashboard', [
            'kpis' => [
                'active_projects' => $activeProjectsCount,
                'open_tasks' => $openTasksCount,
                'overdue_tasks' => $overdueTasksCount,
                'completed_last_30_days' => $completedLast30DaysCount,
            ],
            'projects' => $projects,
            'tasks' => $tasks,
        ]);
    }

    /**
     * PROJECT-01: a real calendar view of tasks by due date — reuses the
     * EXACT same visibility scoping as index() above (project membership +
     * TaskPolicy::scopeVisibleToPerformer() for anyone without project-wide
     * tasks.tasks.view). No separate, looser query for this screen — a
     * plain team member must never see a teammate's task here either.
     */
    public function calendar(Request $request, TaskPolicy $taskPolicy): Response
    {
        /** @var User $user */
        $user = $request->user();

        $month = $request->string('month')->trim()->value();
        $anchor = $month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)
            ? Carbon::createFromFormat('Y-m-d', $month.'-01')
            : Carbon::now()->startOfMonth();

        $rangeStart = $anchor->copy()->startOfMonth();
        $rangeEnd = $anchor->copy()->endOfMonth();

        $tasks = $this->visibleTaskQuery($user, $taskPolicy)
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [$rangeStart, $rangeEnd])
            ->with('project')
            ->orderBy('due_at')
            ->get()
            ->map(fn (Task $task) => [
                'id' => $task->id,
                'project_id' => $task->project_id,
                'title' => $task->title,
                'project_name' => $task->project?->name,
                'status' => $task->status,
                'due_at' => $task->due_at?->toDateString(),
            ]);

        return Inertia::render('Projects/Calendar', [
            'month' => $anchor->toDateString(),
            'tasks' => $tasks,
        ]);
    }

    /**
     * Audit A15 / acceptance NAV-02: the drill-down behind each dashboard
     * KPI card. It deliberately reuses `visibleTaskQuery()` +
     * `applyTaskFilter()` — the very same helpers `index()` counts with —
     * so the number on the card and the rows on this page are the same
     * query, not two similar ones that can silently diverge.
     *
     * This is a dashboard-owned cross-project view, exactly like
     * `calendar()` above; it is not a second task workspace. When the
     * global `/tasks` workspace lands it should absorb this route rather
     * than replicate its data (spec 03 §13.4: "ცალკე რეპლიცირებული
     * მონაცემები არ გამოიყენო ორი სიისთვის").
     */
    public function tasks(Request $request, TaskPolicy $taskPolicy): Response
    {
        /** @var User $user */
        $user = $request->user();

        $filter = $request->string('filter')->trim()->value();
        if (! array_key_exists($filter, self::FILTER_TITLES)) {
            $filter = self::FILTER_OPEN;
        }

        $tasks = $this->applyTaskFilter($this->visibleTaskQuery($user, $taskPolicy), $filter)
            ->with(['project', 'accountableOwner'])
            ->orderByRaw('case when due_at is null then 1 else 0 end')
            ->orderBy('due_at')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Dashboard/Tasks', [
            'filter' => $filter,
            'title' => self::FILTER_TITLES[$filter],
            'tasks' => collect($tasks->items())->map(fn (Task $task) => [
                'id' => $task->id,
                'project_id' => $task->project_id,
                'project_name' => $task->project?->name,
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'due_at' => $task->due_at?->toDateString(),
                'owner_name' => $task->accountableOwner === null
                    ? null
                    : trim($task->accountableOwner->first_name.' '.$task->accountableOwner->last_name),
            ])->all(),
            'pagination' => [
                'page' => $tasks->currentPage(),
                'perPage' => $tasks->perPage(),
                'total' => $tasks->total(),
            ],
        ]);
    }

    /**
     * The single definition of "tasks this user may see": projects they can
     * reach (owner sees the organization, everyone else their active
     * memberships) narrowed by TaskPolicy::scopeVisibleToPerformer() for
     * anyone without project-wide `tasks.tasks.view`. index(), calendar()
     * and tasks() all start here so no screen can quietly widen it.
     *
     * @return Builder<Task>
     */
    private function visibleTaskQuery(User $user, TaskPolicy $taskPolicy): Builder
    {
        $projectsQuery = Project::query();
        if (! $user->can('viewAny', Project::class)) {
            $projectsQuery->whereHas('memberships', function ($q) use ($user) {
                $q->where('user_id', $user->id)->whereNull('removed_at');
            });
        }

        $taskQuery = Task::query()->whereIn('project_id', $projectsQuery->pluck('id'));

        if (! $user->can('tasks.tasks.view')) {
            $taskPolicy->scopeVisibleToPerformer($taskQuery, $user);
        }

        return $taskQuery;
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    private function applyTaskFilter(Builder $query, string $filter): Builder
    {
        return match ($filter) {
            self::FILTER_OVERDUE => $query
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->whereNotNull('due_at')
                ->where('due_at', '<', Carbon::now()),
            self::FILTER_COMPLETED_30D => $query
                ->where('status', 'completed')
                ->where('updated_at', '>=', Carbon::now()->subDays(30)),
            default => $query->whereNotIn('status', ['completed', 'cancelled']),
        };
    }
}
