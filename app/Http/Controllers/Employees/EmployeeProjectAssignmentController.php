<?php

namespace App\Http\Controllers\Employees;

use App\Domain\Employees\Actions\CreateEmployeeProjectAssignmentAction;
use App\Domain\Employees\Models\Employee;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\StoreEmployeeProjectAssignmentRequest;
use Illuminate\Http\RedirectResponse;

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
}
