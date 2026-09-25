<?php

namespace App\Http\Controllers\Employees;

use App\Domain\Employees\Actions\CreateEmployeeProjectAssignmentAction;
use App\Domain\Employees\Actions\UpdateEmployeeProjectAssignmentAction;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\EmployeeProjectAssignment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\StoreEmployeeProjectAssignmentRequest;
use App\Http\Requests\Employees\UpdateEmployeeProjectAssignmentRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class EmployeeProjectAssignmentController extends Controller
{
    public function store(StoreEmployeeProjectAssignmentRequest $request, Employee $employee, CreateEmployeeProjectAssignmentAction $action): RedirectResponse
    {
        $this->authorize('manageProjectAssignments', $employee);
        $action->execute($employee, $request->assignmentData(), $request->user());

        return to_route('employees.show', $employee)->with('toast', [
            'type' => 'success',
            'message' => 'პროექტზე მინიჭების პერიოდი დაემატა.',
        ]);
    }

    /**
     * Audit A10: „არ ჩანს ამ მინიჭების რედაქტირება/დასრულება/გადაყვანა."
     * Only creation existed, so an assignment entered with the wrong date, or
     * one that simply ended, could never be corrected or closed by any route.
     *
     * Correcting a period and ending one are the same write. Both matter
     * beyond tidiness: an assignment period is what decides which project a
     * worked day is attributed to.
     */
    public function update(
        UpdateEmployeeProjectAssignmentRequest $request,
        Employee $employee,
        EmployeeProjectAssignment $assignment,
        UpdateEmployeeProjectAssignmentAction $action,
    ): RedirectResponse {
        $this->authorize('manageProjectAssignments', $employee);

        // The route nests the assignment under the employee, so a mismatch
        // here means an id from someone else's profile was submitted.
        abort_unless($assignment->employee_id === $employee->id, 404);

        try {
            $action->execute($assignment, $request->assignmentData(), $request->user());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return to_route('employees.show', $employee)->with('toast', [
            'type' => 'success',
            'message' => 'მინიჭების პერიოდი განახლდა.',
        ]);
    }
}
