<?php

namespace App\Http\Controllers\DailyJournal;

use App\Domain\DailyJournal\Actions\AcceptDailyReportAction;
use App\Domain\DailyJournal\Actions\CreateDailyReportDraftAction;
use App\Domain\DailyJournal\Actions\ReturnDailyReportAction;
use App\Domain\DailyJournal\Actions\SubmitDailyReportAction;
use App\Domain\DailyJournal\Actions\UpdateDailyReportDraftAction;
use App\Domain\DailyJournal\Exceptions\DailyReportDomainException;
use App\Domain\DailyJournal\Exceptions\DailyReportValidationException;
use App\Domain\DailyJournal\Exceptions\StaleDailyReportVersionException;
use App\Domain\DailyJournal\Models\DailyReport;
use App\Domain\Employees\Models\Team;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\PortableSearch;
use App\Domain\Tasks\Models\Task;
use App\Http\Controllers\Controller;
use App\Http\Requests\DailyJournal\AcceptDailyReportRequest;
use App\Http\Requests\DailyJournal\ReturnDailyReportRequest;
use App\Http\Requests\DailyJournal\StoreDailyReportRequest;
use App\Http\Requests\DailyJournal\SubmitDailyReportRequest;
use App\Http\Requests\DailyJournal\UpdateDailyReportRequest;
use App\Http\Resources\DailyJournal\DailyReportResource;
use App\Http\Resources\DailyJournal\DailyReportRevisionResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin controller: validate request -> call Domain Action -> return Inertia
 * (spec section 18: "ბიზნესწესები Laravel domain services/actions-ში").
 * Every action re-checks the real Policy via `$this->authorize()` — the
 * hard constraint that a hidden menu item is never itself protection.
 */
class DailyReportController extends Controller
{
    /**
     * Parameter-less landing page (routed from config/modules/dailyjournal-nav.php
     * as `daily-journal.projects`) — NavigationService's `route($name)` call
     * has no params to supply, so this module's nav entry must point at a
     * route that needs none. Lists the projects the current user actually
     * has journal access to (owner: every project in the organization;
     * everyone else: their active ProjectMemberships), each linking into
     * that project's own `daily-journal.index`.
     */
    public function projects(Request $request): Response
    {
        abort_unless($request->user()?->can('dailyjournal.reports.view') ?? false, 403);

        $query = Project::query()->orderBy('name');

        if (! $request->user()->hasRole('owner')) {
            $query->whereHas('memberships', function ($membershipQuery) use ($request) {
                $membershipQuery->where('user_id', $request->user()->id)->whereNull('removed_at');
            });
        }

        $projects = $query->get(['id', 'name', 'code']);

        return Inertia::render('DailyJournal/Projects', [
            'projects' => $projects,
        ]);
    }

    public function index(Request $request, Project $project): Response
    {
        $this->authorize('viewAny', [DailyReport::class, $project]);

        $status = $request->query('status');
        $from = $request->query('from');
        $to = $request->query('to');

        $reports = DailyReport::query()
            ->where('project_id', $project->id)
            ->with(['responsible'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($from, fn ($query) => $query->where('report_date', '>=', $from))
            ->when($to, fn ($query) => $query->where('report_date', '<=', $to))
            ->orderByDesc('report_date')
            ->paginate((int) $request->query('per_page', 20))
            ->withQueryString();

        return Inertia::render('DailyJournal/Index', [
            'project' => ['id' => $project->id, 'name' => $project->name, 'code' => $project->code],
            'reports' => DailyReportResource::collection($reports->items()),
            'meta' => [
                'page' => $reports->currentPage(),
                'perPage' => $reports->perPage(),
                'total' => $reports->total(),
            ],
            'filters' => ['status' => $status, 'from' => $from, 'to' => $to],
            'canCreate' => $request->user()?->can('create', [DailyReport::class, $project]) ?? false,
        ]);
    }

    public function create(Project $project): Response
    {
        $this->authorize('create', [DailyReport::class, $project]);

        return Inertia::render('DailyJournal/Form', [
            'project' => ['id' => $project->id, 'name' => $project->name, 'code' => $project->code],
            'teams' => Team::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'tasks' => Task::query()->where('project_id', $project->id)->orderBy('title')->get(['id', 'title']),
            'report' => null,
        ]);
    }

    /**
     * Audit A12: the responsible person used to be a hand-typed UUID field.
     * This is the server-side searchable selector behind it (spec 02 §10) —
     * resources/js/components/EntityPicker.vue calls it and never shows the
     * id at all.
     *
     * Scoped exactly like Projects\ProjectController::create()'s own manager
     * list: real login accounts of the caller's organization, system actors
     * excluded. Gated by `viewAny` on this project's journal, so the list
     * discloses nothing to someone who could not already open the journal.
     * The ids it returns are still re-validated by
     * StoreDailyReportRequest/UpdateDailyReportRequest — picking from this
     * list is a convenience, never the authorization boundary.
     */
    public function responsibleUserOptions(Request $request, Project $project): JsonResponse
    {
        $this->authorize('viewAny', [DailyReport::class, $project]);

        $term = trim((string) $request->query('q', ''));

        $users = User::query()
            ->where('organization_id', $request->user()->organization_id)
            ->where('is_system_account', false)
            ->when($term !== '', function ($query) use ($term): void {
                PortableSearch::where($query, 'name', "%{$term}%");
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name']);

        $memberUserIds = $project->memberships()
            ->whereNull('removed_at')
            ->pluck('user_id')
            ->all();

        return response()->json([
            'options' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'label' => $user->name,
                // Disambiguates same-named colleagues without exposing an
                // email address or any other contact detail.
                'sublabel' => in_array($user->id, $memberUserIds, true) ? 'პროექტის წევრი' : null,
            ])->all(),
        ]);
    }

    public function store(StoreDailyReportRequest $request, Project $project, CreateDailyReportDraftAction $action): RedirectResponse
    {
        $this->authorize('create', [DailyReport::class, $project]);

        try {
            $report = $action->execute(
                $project,
                $request->safe()->except(['team_ids', 'task_ids']) + ['team_ids' => $request->input('team_ids', [])],
                $request->input('task_ids', []),
                $request->user(),
            );
        } catch (DailyReportDomainException $exception) {
            return back()->withErrors(['report_date' => $exception->getMessage()])->withInput();
        }

        return Redirect::route('daily-journal.show', [$project, $report])
            ->with('success', 'ჟურნალის ჩანაწერი შეიქმნა.');
    }

    public function show(Project $project, DailyReport $report): Response
    {
        $this->ensureReportBelongsToProject($project, $report);
        $this->authorize('view', $report);

        $report->load(['responsible', 'submittedBy', 'acceptedBy', 'taskLinks.task']);
        $report->loadCount('revisions');

        return Inertia::render('DailyJournal/Show', [
            'project' => ['id' => $project->id, 'name' => $project->name, 'code' => $project->code],
            'report' => (new DailyReportResource($report))->resolve(),
            'teams' => Team::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'can' => [
                'update' => request()->user()?->can('update', $report) ?? false,
                'submit' => request()->user()?->can('submit', $report) ?? false,
                'accept' => request()->user()?->can('accept', $report) ?? false,
                'return' => request()->user()?->can('return', $report) ?? false,
            ],
        ]);
    }

    public function edit(Project $project, DailyReport $report): Response
    {
        $this->ensureReportBelongsToProject($project, $report);
        $this->authorize('update', $report);

        // `responsible` is loaded so DailyReportResource emits
        // `responsible_name` — the selector shows the already-chosen person
        // by name; the uuid alone would leave the field looking empty (A12).
        $report->load(['taskLinks.task', 'responsible']);

        return Inertia::render('DailyJournal/Form', [
            'project' => ['id' => $project->id, 'name' => $project->name, 'code' => $project->code],
            'teams' => Team::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'tasks' => Task::query()->where('project_id', $project->id)->orderBy('title')->get(['id', 'title']),
            'report' => (new DailyReportResource($report))->resolve(),
        ]);
    }

    public function update(UpdateDailyReportRequest $request, Project $project, DailyReport $report, UpdateDailyReportDraftAction $action): RedirectResponse
    {
        $this->ensureReportBelongsToProject($project, $report);
        $this->authorize('update', $report);

        $data = $request->safe()->except(['target_version', 'reason', 'team_ids', 'task_ids']);
        if ($request->has('team_ids')) {
            $data['team_ids'] = $request->input('team_ids');
        }

        try {
            $action->execute(
                $report,
                $data,
                $request->has('task_ids') ? $request->input('task_ids') : null,
                (int) $request->input('target_version'),
                $request->user(),
                $request->input('reason'),
            );
        } catch (StaleDailyReportVersionException $exception) {
            return back()->withErrors(['version' => $exception->getMessage()])->withInput();
        } catch (DailyReportDomainException $exception) {
            return back()->withErrors(['report' => $exception->getMessage()])->withInput();
        }

        return Redirect::route('daily-journal.show', [$project, $report])
            ->with('success', 'ჟურნალის ჩანაწერი განახლდა.');
    }

    public function submit(SubmitDailyReportRequest $request, Project $project, DailyReport $report, SubmitDailyReportAction $action): RedirectResponse
    {
        $this->ensureReportBelongsToProject($project, $report);
        $this->authorize('submit', $report);

        try {
            $action->execute($report, (int) $request->input('target_version'), $request->user());
        } catch (DailyReportValidationException $exception) {
            return back()->withErrors($exception->fieldErrors)->withInput();
        } catch (StaleDailyReportVersionException $exception) {
            return back()->withErrors(['version' => $exception->getMessage()])->withInput();
        } catch (DailyReportDomainException $exception) {
            return back()->withErrors(['report' => $exception->getMessage()])->withInput();
        }

        return Redirect::route('daily-journal.show', [$project, $report])
            ->with('success', 'ჟურნალი წარდგენილია მენეჯერის მისაღებად.');
    }

    public function accept(AcceptDailyReportRequest $request, Project $project, DailyReport $report, AcceptDailyReportAction $action): RedirectResponse
    {
        $this->ensureReportBelongsToProject($project, $report);
        $this->authorize('accept', $report);

        try {
            $action->execute($report, (int) $request->input('target_version'), $request->user(), $request->input('notes'));
        } catch (StaleDailyReportVersionException $exception) {
            return back()->withErrors(['version' => $exception->getMessage()])->withInput();
        } catch (DailyReportDomainException $exception) {
            return back()->withErrors(['report' => $exception->getMessage()])->withInput();
        }

        return Redirect::route('daily-journal.show', [$project, $report])
            ->with('success', 'ჟურნალი მიღებულია.');
    }

    public function return(ReturnDailyReportRequest $request, Project $project, DailyReport $report, ReturnDailyReportAction $action): RedirectResponse
    {
        $this->ensureReportBelongsToProject($project, $report);
        $this->authorize('return', $report);

        try {
            $action->execute($report, (int) $request->input('target_version'), $request->user(), $request->input('reason'));
        } catch (StaleDailyReportVersionException $exception) {
            return back()->withErrors(['version' => $exception->getMessage()])->withInput();
        } catch (DailyReportDomainException $exception) {
            return back()->withErrors(['report' => $exception->getMessage()])->withInput();
        }

        return Redirect::route('daily-journal.show', [$project, $report])
            ->with('success', 'ჟურნალი დაბრუნდა შესასწორებლად.');
    }

    public function revisions(Project $project, DailyReport $report): Response
    {
        $this->ensureReportBelongsToProject($project, $report);
        $this->authorize('view', $report);

        $revisions = $report->revisions()->with('revisedBy')->orderByDesc('revised_at')->get();

        return Inertia::render('DailyJournal/Revisions', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'report' => (new DailyReportResource($report))->resolve(),
            'revisions' => DailyReportRevisionResource::collection($revisions)->resolve(),
        ]);
    }

    /**
     * Nested route safety: `/projects/{project}/daily-journal/{report}`
     * binds `$project` and `$report` independently, so without this check a
     * report ID could be requested under an unrelated project's URL. 404s
     * (never 403) so existence of a report outside the given project is not
     * disclosed — same "policy doesn't reveal existence of protected
     * objects" rule spec section 20 states for 401/403/404 generally.
     */
    private function ensureReportBelongsToProject(Project $project, DailyReport $report): void
    {
        abort_unless($report->project_id === $project->id, 404);
    }
}
