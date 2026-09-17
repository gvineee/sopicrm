<?php

namespace App\Http\Controllers\Employees;

use App\Domain\Employees\Actions\TerminateEmploymentAction;
use App\Domain\Employees\Models\Employee;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\TerminateEmploymentRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * spec section 5 termination workflow. The redirect flashes a structured
 * `termination` payload (login-revoked flag, count of scheduled device sync
 * commands, and any unreturned custody transactions) so the UI can surface
 * exactly what happened — never silently hides the unreturned-tools list.
 */
class EmploymentController extends Controller
{
    use AuthorizesRequests;

    public function store(TerminateEmploymentRequest $request, Employee $employee, TerminateEmploymentAction $action): RedirectResponse
    {
        $this->authorize('terminate', $employee);

        try {
            $outcome = $action->execute($employee, $request->validated('ended_on'), $request->validated('end_reason'), $request->user());
        } catch (RuntimeException $exception) {
            return back()->withErrors(['end_reason' => $exception->getMessage()]);
        }

        return to_route('employees.show', $employee)
            ->with('toast', ['type' => 'success', 'message' => 'დასაქმება დასრულებულია.'])
            ->with('termination', $outcome->toArray());
    }
}
