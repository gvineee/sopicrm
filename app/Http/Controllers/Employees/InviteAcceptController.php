<?php

namespace App\Http\Controllers\Employees;

use App\Domain\Employees\Actions\AcceptEmployeeInviteAction;
use App\Domain\Employees\Exceptions\InvalidEmployeeInviteException;
use App\Domain\Employees\Models\EmployeeInvite;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\AcceptEmployeeInviteRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public, UNAUTHENTICATED endpoint — the one-time token in the URL is the
 * credential (spec section 5: "ერთჯერადი ბმული ვადიანი იყოს"). Deliberately
 * shows the same neutral "invalid or expired" state for every failure mode
 * (unknown token, already-used, revoked, expired) so a probing request can't
 * distinguish them.
 */
class InviteAcceptController extends Controller
{
    public function show(string $token): Response
    {
        $invite = EmployeeInvite::withoutTenantScope()
            ->with('employee')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        $valid = $invite !== null
            && $invite->status === 'pending'
            && ! $invite->expires_at->isPast()
            && $invite->employee !== null
            && $invite->employee->user_id === null;

        return Inertia::render('auth/EmployeeInviteAccept', [
            'token' => $token,
            'valid' => $valid,
            'employeeName' => $valid ? trim("{$invite->employee->first_name} {$invite->employee->last_name}") : null,
        ]);
    }

    public function store(AcceptEmployeeInviteRequest $request, string $token, AcceptEmployeeInviteAction $action): RedirectResponse
    {
        try {
            $user = $action->execute($token, $request->inviteData());
        } catch (InvalidEmployeeInviteException $exception) {
            return back()->withErrors(['token' => $exception->getMessage()]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return to_route('dashboard')->with('toast', [
            'type' => 'success',
            'message' => 'ანგარიში წარმატებით შეიქმნა.',
        ]);
    }
}
