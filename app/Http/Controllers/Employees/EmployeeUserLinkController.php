<?php

namespace App\Http\Controllers\Employees;

use App\Domain\Employees\Actions\LinkEmployeeToUserAction;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Services\PortableSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\LinkEmployeeUserRequest;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Audit A11: attaches an EXISTING account to an employee record. Until this
 * existed, the only way an employee ever gained a login was an invite that
 * creates a NEW user, so anyone who already had an account could never be
 * connected to their own employee record and „ჩემი დღე"/„ჩემი პროფილი"
 * stayed permanently empty for them.
 *
 * Both endpoints are gated by the same authority as issuing an invite
 * (`manageInvite`), because both answer the same question: who may act in
 * this system as this person. The real rules live in
 * App\Domain\Employees\Actions\LinkEmployeeToUserAction — the options list
 * below is a convenience for the picker and never an authorization boundary.
 */
class EmployeeUserLinkController extends Controller
{
    use AuthorizesRequests;

    /**
     * Accounts in this organization that no employee record claims yet. An
     * account already linked elsewhere is left out so the picker cannot
     * suggest a choice the Action is bound to refuse.
     */
    public function options(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('manageInvite', $employee);

        $term = trim((string) $request->query('q', ''));

        $takenUserIds = Employee::query()
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->all();

        $users = User::query()
            ->where('organization_id', $request->user()->organization_id)
            ->where('is_system_account', false)
            ->whereNotIn('id', $takenUserIds)
            ->when($term !== '', function ($query) use ($term): void {
                $query->where(function ($inner) use ($term): void {
                    PortableSearch::where($inner, 'name', "%{$term}%");
                    PortableSearch::orWhere($inner, 'email', "%{$term}%");
                });
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'email', 'is_active']);

        return response()->json([
            'options' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'label' => $user->name,
                // The email is the only reliable way to tell two people with
                // the same display name apart, and whoever may link accounts
                // can already read the user list.
                'sublabel' => $user->is_active ? $user->email : $user->email.' — დეაქტივირებული',
            ])->all(),
        ]);
    }

    public function store(LinkEmployeeUserRequest $request, Employee $employee, LinkEmployeeToUserAction $action): RedirectResponse
    {
        $this->authorize('manageInvite', $employee);

        $account = User::query()
            ->where('organization_id', $request->user()->organization_id)
            ->find($request->accountId());

        if ($account === null) {
            return back()->withErrors(['user_id' => 'ანგარიში ვერ მოიძებნა ამ ორგანიზაციაში.']);
        }

        try {
            $action->execute($employee, $account, $request->user());
        } catch (RuntimeException $exception) {
            return back()->withErrors(['user_id' => $exception->getMessage()]);
        }

        return to_route('employees.show', $employee)
            ->with('toast', ['type' => 'success', 'message' => 'ანგარიში დაუკავშირდა თანამშრომელს.']);
    }
}
