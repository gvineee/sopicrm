<?php

namespace App\Http\Controllers\Employees;

use App\Domain\Employees\Actions\IssueEmployeeInviteAction;
use App\Domain\Employees\Actions\RevokeEmployeeInviteAction;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\EmployeeInvite;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\StoreEmployeeInviteRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * spec section 5 HR-issued invite flow. The raw one-time token is shown to
 * HR exactly once, in the redirect's flashed session data — it is never
 * persisted anywhere in plaintext (App\Domain\Employees\Models\EmployeeInvite
 * stores only its SHA-256 hash) and never logged.
 */
class EmployeeInviteController extends Controller
{
    use AuthorizesRequests;

    public function store(StoreEmployeeInviteRequest $request, Employee $employee, IssueEmployeeInviteAction $action): RedirectResponse
    {
        $this->authorize('manageInvite', $employee);

        try {
            ['token' => $token] = $action->execute($employee, $request->user());
        } catch (RuntimeException $exception) {
            return back()->withErrors(['invite' => $exception->getMessage()]);
        }

        return to_route('employees.show', $employee)
            ->with('toast', ['type' => 'success', 'message' => 'მოწვევის ბმული შეიქმნა.'])
            ->with('inviteUrl', route('employees.invite.show', ['token' => $token]));
    }

    public function destroy(EmployeeInvite $invite, RevokeEmployeeInviteAction $action): RedirectResponse
    {
        $employee = $invite->employee;

        $this->authorize('manageInvite', $employee);

        $action->execute($invite, request()->user());

        return to_route('employees.show', $employee)->with('toast', [
            'type' => 'success',
            'message' => 'მოწვევა გაუქმებულია.',
        ]);
    }
}
