<?php

namespace App\Http\Controllers;

use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use App\Policies\TaskPolicy;
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
        $visibleProjectIds = (clone $projectsQuery)->pluck('id');

        $activeProjectsCount = (clone $projectsQuery)->where('status', 'active')->count();

        // Being able to see a project (membership) is not the same as being
        // able to see every task inside it (audit finding FIX-02/A3,
        // 2026-09-21) — a user without project-wide `tasks.tasks.view` only
        // ever sees tasks they are the performer of, matching
        // TaskPolicy::view()'s own rule exactly (see
        // TaskPolicy::scopeVisibleToPerformer()).
        $taskBase = Task::query()->whereIn('project_id', $visibleProjectIds);
        if (! $user->can('tasks.tasks.view')) {
            $taskPolicy->scopeVisibleToPerformer($taskBase, $user);
        }

        $openTasksCount = (clone $taskBase)->whereNotIn('status', ['completed', 'cancelled'])->count();
        $overdueTasksCount = (clone $taskBase)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->count();
        $completedLast30DaysCount = (clone $taskBase)
            ->where('status', 'completed')
            ->where('updated_at', '>=', Carbon::now()->subDays(30))
            ->count();

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

        $projectsQuery = Project::query();
        if (! $user->can('viewAny', Project::class)) {
            $projectsQuery->whereHas('memberships', function ($q) use ($user) {
                $q->where('user_id', $user->id)->whereNull('removed_at');
            });
        }
        $visibleProjectIds = $projectsQuery->pluck('id');

        $taskBase = Task::query()->whereIn('project_id', $visibleProjectIds);
        if (! $user->can('tasks.tasks.view')) {
            $taskPolicy->scopeVisibleToPerformer($taskBase, $user);
        }

        $tasks = $taskBase
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
}
