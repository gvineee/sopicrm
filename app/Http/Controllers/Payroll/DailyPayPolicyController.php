<?php

namespace App\Http\Controllers\Payroll;

use App\Domain\Payroll\Actions\ConfirmDailyPayPolicyAction;
use App\Domain\Payroll\Models\DailyPayPolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\ConfirmDailyPayPolicyRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DailyPayPolicyController extends Controller
{
    use AuthorizesRequests;

    public function edit(): Response
    {
        $this->authorize('view', DailyPayPolicy::class);

        $policy = DailyPayPolicy::query()->first();

        return Inertia::render('Payroll/DailyPayPolicy/Edit', [
            'policy' => $policy,
            'canManage' => auth()->user()?->can('update', [DailyPayPolicy::class, $policy]) ?? false,
        ]);
    }

    public function update(ConfirmDailyPayPolicyRequest $request, ConfirmDailyPayPolicyAction $action): RedirectResponse
    {
        $this->authorize('update', [DailyPayPolicy::class, DailyPayPolicy::query()->first()]);

        $action->execute($request->policyData(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'დღიური ანაზღაურების პოლიტიკა დადასტურდა.']);
    }
}
