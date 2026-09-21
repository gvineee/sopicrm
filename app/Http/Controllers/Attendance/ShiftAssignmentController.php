<?php

namespace App\Http\Controllers\Attendance;

use App\Domain\Attendance\Actions\CreateShiftAssignmentAction;
use App\Domain\Attendance\Actions\EndShiftAssignmentAction;
use App\Domain\Attendance\Models\ShiftAssignment;
use App\Domain\Attendance\Models\ShiftTemplate;
use App\Domain\Employees\Models\Employee;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\EndShiftAssignmentRequest;
use App\Http\Requests\Attendance\ShiftAssignmentRequest;
use App\Http\Resources\Attendance\ShiftAssignmentResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShiftAssignmentController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ShiftAssignment::class);

        $assignments = ShiftAssignment::query()
            ->with(['employee', 'shiftTemplate'])
            ->orderByDesc('effective_from')
            ->get();

        return Inertia::render('Attendance/ShiftAssignments/Index', [
            'shiftAssignments' => ShiftAssignmentResource::collection($assignments),
            'employees' => Employee::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'shiftTemplates' => ShiftTemplate::query()->orderBy('name')->get(['id', 'name']),
            'canManage' => $request->user()->can('create', ShiftAssignment::class),
        ]);
    }

    public function store(ShiftAssignmentRequest $request, CreateShiftAssignmentAction $action): RedirectResponse
    {
        $this->authorize('create', ShiftAssignment::class);

        $action->execute($request->assignmentData(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'ცვლაზე მინიჭება დაემატა.']);
    }

    public function end(EndShiftAssignmentRequest $request, ShiftAssignment $shiftAssignment, EndShiftAssignmentAction $action): RedirectResponse
    {
        $this->authorize('update', $shiftAssignment);

        $action->execute($shiftAssignment, (string) $request->validated('effective_to'), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'ცვლაზე მინიჭება დასრულდა.']);
    }
}
