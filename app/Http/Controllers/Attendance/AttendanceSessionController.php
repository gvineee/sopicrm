<?php

namespace App\Http\Controllers\Attendance;

use App\Domain\Attendance\Actions\AttributeAttendanceSessionProjectAction;
use App\Domain\Attendance\Actions\ReconstructAttendanceSessionsAction;
use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Employees\Models\Employee;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AttributeSessionProjectRequest;
use App\Http\Requests\Attendance\ReconstructAttendanceSessionsRequest;
use App\Http\Resources\Attendance\AttendanceAnomalyResource;
use App\Http\Resources\Attendance\AttendanceSessionResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceSessionController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AttendanceSession::class);

        $sessions = AttendanceSession::query()
            ->with(['employee', 'site', 'project'])
            ->withCount('anomalies')
            ->where('status', '!=', 'superseded')
            ->when($request->string('employee_id')->toString(), fn ($query, $employeeId) => $query->where('employee_id', $employeeId))
            ->orderByDesc('work_date')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Attendance/Sessions/Index', [
            'sessions' => AttendanceSessionResource::collection($sessions),
            'employees' => Employee::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'canReconstruct' => $request->user()->can('reconstruct', AttendanceSession::class),
            'filters' => ['employee_id' => $request->string('employee_id')->toString() ?: null],
        ]);
    }

    public function show(AttendanceSession $session): Response
    {
        $this->authorize('view', $session);

        $session->load(['employee', 'site', 'project', 'clockInEvent', 'clockOutEvent', 'anomalies']);

        return Inertia::render('Attendance/Sessions/Show', [
            'session' => new AttendanceSessionResource($session),
            'anomalies' => AttendanceAnomalyResource::collection($session->anomalies),
            'canManage' => auth()->user()?->can('attributeProject', $session) ?? false,
        ]);
    }

    public function reconstruct(ReconstructAttendanceSessionsRequest $request, ReconstructAttendanceSessionsAction $action): RedirectResponse
    {
        $this->authorize('reconstruct', AttendanceSession::class);

        $employee = Employee::query()->findOrFail((string) $request->validated('employee_id'));

        $sessions = $action->handle(
            $employee,
            Carbon::parse((string) $request->validated('from')),
            Carbon::parse((string) $request->validated('to')),
            $request->user(),
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => count($sessions).' სესია აღდგენილია.',
        ]);
    }

    public function attributeProject(AttributeSessionProjectRequest $request, AttendanceSession $session, AttributeAttendanceSessionProjectAction $action): RedirectResponse
    {
        $this->authorize('attributeProject', $session);

        $action->execute($session, (string) $request->validated('project_id'), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'პროექტი მიბმულია სესიაზე.']);
    }
}
