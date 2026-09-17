<?php

namespace App\Http\Controllers\Projects;

use App\Domain\Projects\Actions\CreateProjectAction;
use App\Domain\Projects\Actions\UpdateProjectAction;
use App\Domain\Projects\Exceptions\ProjectDomainException;
use App\Domain\Projects\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Services\ProjectStatusTransitionService;
use App\Domain\Shared\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Http\Resources\Projects\ClientResource;
use App\Http\Resources\Projects\ProjectDetailResource;
use App\Http\Resources\Projects\ProjectResource;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin controller: validate -> Policy -> Domain Action -> Inertia response
 * (docs/architecture.md §2). Server-side search/sort/pagination throughout
 * (spec section 4: "ძიება, სორტირება და ფილტრები სერვერულია; გამოიყენე
 * pagination").
 */
class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->can('projects.view'), 403);

        $query = Project::query()->with(['client', 'manager'])->withCount('memberships');

        // Spec section 3: PM/other non-owner roles only ever see projects
        // they're an active member of — the real access boundary, not just
        // a hidden menu item.
        if (! $user->can('viewAny', Project::class)) {
            $query->whereHas('memberships', function ($q) use ($user) {
                $q->where('user_id', $user->id)->whereNull('removed_at');
            });
        }

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('code', 'ilike', "%{$search}%");
            });
        }

        if ($status = $request->string('status')->trim()->value()) {
            $query->where('status', $status);
        }

        if ($clientId = $request->string('client_id')->trim()->value()) {
            $query->where('client_id', $clientId);
        }

        $sortKey = $request->string('sort')->value() ?: 'name';
        $direction = $request->string('direction')->value() === 'desc' ? 'desc' : 'asc';
        $allowedSorts = ['name', 'code', 'status', 'starts_on', 'ends_on', 'created_at'];
        if (! in_array($sortKey, $allowedSorts, true)) {
            $sortKey = 'name';
        }
        $query->orderBy($sortKey, $direction);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));
        $projects = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Projects/Index', [
            'projects' => ProjectResource::collection($projects->items()),
            'pagination' => [
                'page' => $projects->currentPage(),
                'perPage' => $projects->perPage(),
                'total' => $projects->total(),
            ],
            'filters' => [
                'search' => $search ?: '',
                'status' => $status ?: '',
                'client_id' => $clientId ?: '',
            ],
            'sort' => ['key' => $sortKey, 'direction' => $direction],
            'clients' => ClientResource::collection(Client::query()->orderBy('name')->get()),
            'can' => [
                'create' => $user->can('create', Project::class),
                'view_any' => $user->can('viewAny', Project::class),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Project::class);

        return Inertia::render('Projects/Create', [
            'clients' => ClientResource::collection(Client::query()->orderBy('name')->get()),
            'managers' => User::query()
                ->where('organization_id', $request->user()->organization_id)
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
        ]);
    }

    public function store(StoreProjectRequest $request, CreateProjectAction $action): RedirectResponse
    {
        $project = $action->execute($request->validated(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'პროექტი წარმატებით შეიქმნა.']);

        return to_route('projects.show', $project);
    }

    public function show(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        $project->load(['client', 'manager']);

        return Inertia::render('Projects/Show', [
            'project' => new ProjectDetailResource($project),
            'statusOptions' => app(ProjectStatusTransitionService::class)->allowedTargets($project->status),
        ]);
    }

    public function edit(Request $request, Project $project): Response
    {
        $this->authorize('update', $project);

        $project->load(['client', 'manager']);

        return Inertia::render('Projects/Edit', [
            'project' => new ProjectDetailResource($project),
            'clients' => ClientResource::collection(Client::query()->orderBy('name')->get()),
            'managers' => User::query()
                ->where('organization_id', $request->user()->organization_id)
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project, UpdateProjectAction $action): RedirectResponse
    {
        try {
            $action->execute($project, $request->validated(), $request->user());
        } catch (ProjectDomainException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back()->withErrors($e->fieldErrors());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'ცვლილებები შენახულია.']);

        return to_route('projects.show', $project);
    }

    public function destroy(Request $request, Project $project, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize('delete', $project);

        $before = $project->only(['name', 'code', 'status']);
        $project->delete();

        $auditLogger->log(
            action: 'projects.project.deleted',
            target: $project,
            before: $before,
            actor: $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'პროექტი წაშლილია.']);

        return to_route('projects.index');
    }
}
