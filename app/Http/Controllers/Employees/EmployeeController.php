<?php

namespace App\Http\Controllers\Employees;

use App\Domain\Employees\Actions\CreateEmployeeAction;
use App\Domain\Employees\Actions\UpdateEmployeeAction;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\EmployeeInvite;
use App\Domain\Employees\Models\EmployeeProjectAssignment;
use App\Domain\Employees\Models\Employment;
use App\Domain\Employees\Models\Position;
use App\Domain\Employees\Models\RateHistory;
use App\Domain\Employees\Models\Team;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Models\Attachment;
use App\Domain\Shared\Services\PortableSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\StoreEmployeeRequest;
use App\Http\Requests\Employees\UpdateEmployeeRequest;
use App\Http\Resources\Employees\EmployeeResource;
use App\Http\Resources\Employees\RateHistoryResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * spec section 5 — Employee profile CRUD. Every action re-checks the real
 * Policy server-side (hard constraint: hiding a menu item is never
 * sufficient) via EmployeePolicy, which itself re-checks organization_id —
 * defense-in-depth alongside the BelongsToOrganization global scope and
 * Postgres RLS that already make a cross-tenant {employee} route-model-bind
 * 404 before this code even runs.
 */
class EmployeeController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Employee::class);

        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');
        $teamId = $request->query('team_id');
        $positionId = $request->query('position_id');
        $supervisorId = $request->query('supervisor_employee_id');

        $employees = Employee::query()
            ->with(['team', 'jobPosition'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $like = "%{$search}%";
                    PortableSearch::where($inner, 'internal_code', $like);
                    PortableSearch::orWhere($inner, 'first_name', $like);
                    PortableSearch::orWhere($inner, 'last_name', $like);
                });
            })
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($teamId, fn ($query) => $query->where('team_id', $teamId))
            ->when($positionId, fn ($query) => $query->where('position_id', $positionId))
            ->when($supervisorId, fn ($query) => $query->where('supervisor_employee_id', $supervisorId))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(20)
            ->withQueryString();

        $employees->through(fn (Employee $employee) => (new EmployeeResource($employee))->resolve($request) + [
            'team_name' => $employee->team?->name,
        ]);

        return Inertia::render('Employees/Index', [
            'employees' => $employees,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'team_id' => $teamId,
                'position_id' => $positionId,
                'supervisor_employee_id' => $supervisorId,
            ],
            'teams' => Team::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'positions' => Position::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'supervisors' => Employee::query()->where('status', 'active')->orderBy('last_name')->get(['id', 'first_name', 'last_name']),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Employee::class);

        return Inertia::render('Employees/Create', [
            'teams' => Team::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'supervisors' => Employee::query()->where('status', 'active')->orderBy('last_name')->get(['id', 'first_name', 'last_name']),
            'positions' => Position::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreEmployeeRequest $request, CreateEmployeeAction $action): RedirectResponse
    {
        $this->authorize('create', Employee::class);

        $employee = $action->execute($request->employeeData(), $request->user());

        return to_route('employees.show', $employee)->with('toast', [
            'type' => 'success',
            'message' => 'თანამშრომელი წარმატებით დაემატა.',
        ]);
    }

    public function show(Request $request, Employee $employee): Response
    {
        $this->authorize('view', $employee);

        $employee->load(['team', 'jobPosition', 'supervisor', 'employments' => fn ($query) => $query->orderByDesc('started_at')]);

        $canViewRates = $request->user()->can('viewAny', [RateHistory::class, $employee->id]);
        $canManageRates = $request->user()->can('create', RateHistory::class);
        $canViewDocuments = $request->user()->can('viewDocuments', $employee);

        return Inertia::render('Employees/Show', [
            'employee' => (new EmployeeResource($employee))->resolve($request),
            'employments' => $employee->employments->map(fn (Employment $employment) => [
                'id' => $employment->id,
                'started_at' => $employment->started_at->toDateString(),
                'ended_at' => $employment->ended_at?->toDateString(),
                'end_reason' => $employment->end_reason,
                'status' => $employment->status,
            ]),
            'rateHistories' => $canViewRates
                ? RateHistoryResource::collection(
                    $employee->rateHistories()->with(['project', 'approvedBy'])->orderByDesc('effective_from')->get()
                )->resolve($request)
                : null,
            'canViewRates' => $canViewRates,
            'canManageRates' => $canManageRates,
            'canViewDocuments' => $canViewDocuments,
            'canManageDocuments' => $request->user()->can('manageDocuments', $employee),
            'documents' => $canViewDocuments
                ? $employee->documents()->latest()->get()->map(fn (Attachment $attachment) => [
                    'id' => $attachment->id,
                    'name' => $attachment->original_filename,
                    'caption' => $attachment->caption,
                    'mime_type' => $attachment->mime_type,
                    'byte_size' => $attachment->byte_size,
                    'download_url' => route('employees.documents.download', [$employee, $attachment]),
                ])
                : [],
            'canManageInvite' => $request->user()->can('manageInvite', $employee),
            'canTerminate' => $request->user()->can('terminate', $employee),
            'canEdit' => $request->user()->can('update', $employee),
            'teams' => Team::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'projects' => $canManageRates || $request->user()->can('manageProjectAssignments', $employee)
                ? Project::query()->orderBy('name')->get(['id', 'name'])
                : [],
            'projectAssignments' => $employee->projectAssignments()->with('project')->orderByDesc('starts_on')->get()->map(fn (EmployeeProjectAssignment $assignment) => [
                'id' => $assignment->id,
                'project_id' => $assignment->project_id,
                'project_name' => $assignment->project?->name,
                'starts_on' => $assignment->starts_on->toDateString(),
                'ends_on' => $assignment->ends_on?->toDateString(),
                'assignment_type' => $assignment->assignment_type,
            ]),
            'pendingInvite' => $employee->user_id === null
                ? EmployeeInvite::query()
                    ->where('employee_id', $employee->id)
                    ->where('status', 'pending')
                    ->latest('created_at')
                    ->first(['id', 'expires_at', 'created_at'])
                : null,
            'inviteUrl' => $request->session()->get('inviteUrl'),
            'termination' => $request->session()->get('termination'),
        ]);
    }

    public function edit(Request $request, Employee $employee): Response
    {
        $this->authorize('update', $employee);
        $employee->load(['team', 'jobPosition', 'supervisor']);

        return Inertia::render('Employees/Edit', [
            'employee' => (new EmployeeResource($employee))->resolve($request),
            'teams' => Team::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'positions' => Position::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'supervisors' => Employee::query()
                ->where('status', 'active')
                ->where('id', '!=', $employee->id)
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name']),
        ]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee, UpdateEmployeeAction $action): RedirectResponse
    {
        $this->authorize('update', $employee);

        $action->execute($employee, $request->validated(), $request->user());

        return to_route('employees.show', $employee)->with('toast', [
            'type' => 'success',
            'message' => 'ცვლილებები შენახულია.',
        ]);
    }
}
