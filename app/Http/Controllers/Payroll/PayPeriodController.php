<?php

namespace App\Http\Controllers\Payroll;

use App\Domain\Payroll\Actions\ClosePayPeriodAction;
use App\Domain\Payroll\Actions\CreatePayPeriodAction;
use App\Domain\Payroll\Models\PayPeriod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\PayPeriodRequest;
use App\Http\Resources\Payroll\PayPeriodResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PayPeriodController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PayPeriod::class);

        $payPeriods = PayPeriod::query()
            ->withCount('payRuns')
            ->orderByDesc('starts_on')
            ->get();

        return Inertia::render('Payroll/PayPeriods/Index', [
            'payPeriods' => PayPeriodResource::collection($payPeriods),
            'canManage' => $request->user()->can('create', PayPeriod::class),
        ]);
    }

    public function store(PayPeriodRequest $request, CreatePayPeriodAction $action): RedirectResponse
    {
        $this->authorize('create', PayPeriod::class);

        try {
            $action->execute((string) $request->validated('starts_on'), (string) $request->validated('ends_on'), $request->user());
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['ends_on' => [$e->getMessage()]]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'ანაზღაურების პერიოდი დაემატა.']);
    }

    public function close(PayPeriod $payPeriod, ClosePayPeriodAction $action): RedirectResponse
    {
        $this->authorize('close', $payPeriod);

        $action->execute($payPeriod, request()->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'პერიოდი დაიხურა.']);
    }
}
