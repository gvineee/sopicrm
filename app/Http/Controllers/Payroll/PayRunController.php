<?php

namespace App\Http\Controllers\Payroll;

use App\Domain\Payroll\Actions\ApprovePayRunAction;
use App\Domain\Payroll\Actions\CalculatePayRunAction;
use App\Domain\Payroll\Actions\CreatePayRunAction;
use App\Domain\Payroll\Actions\LockPayRunAction;
use App\Domain\Payroll\Actions\ReviewPayRunAction;
use App\Domain\Payroll\Models\PayPeriod;
use App\Domain\Payroll\Models\PayRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\PayRunVersionActionRequest;
use App\Http\Resources\Payroll\PayPeriodResource;
use App\Http\Resources\Payroll\PayRunResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PayRunController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PayRun::class);

        $payRuns = PayRun::query()
            ->with('payPeriod')
            ->withSum('lines', 'net_amount')
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Payroll/PayRuns/Index', [
            'payRuns' => PayRunResource::collection($payRuns),
            'payPeriods' => PayPeriodResource::collection(PayPeriod::query()->orderByDesc('starts_on')->get()),
            'canCreate' => $request->user()->can('create', PayRun::class),
        ]);
    }

    public function store(Request $request, CreatePayRunAction $action): RedirectResponse
    {
        $this->authorize('create', PayRun::class);

        $payPeriod = PayPeriod::query()->findOrFail((string) $request->string('pay_period_id'));

        try {
            $payRun = $action->execute($payPeriod, $request->user());
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['pay_period_id' => [$e->getMessage()]]);
        }

        return to_route('payroll.pay-runs.show', $payRun);
    }

    public function show(PayRun $payRun): Response
    {
        $this->authorize('view', $payRun);

        $payRun->load(['payPeriod', 'lines.employee', 'lines.project']);

        return Inertia::render('Payroll/PayRuns/Show', [
            'payRun' => new PayRunResource($payRun),
            'canCalculate' => auth()->user()?->can('calculate', $payRun) ?? false,
            'canReview' => auth()->user()?->can('review', $payRun) ?? false,
            'canApprove' => auth()->user()?->can('approve', $payRun) ?? false,
            'canLock' => auth()->user()?->can('lock', $payRun) ?? false,
        ]);
    }

    public function calculate(PayRun $payRun, CalculatePayRunAction $action): RedirectResponse
    {
        $this->authorize('calculate', $payRun);

        try {
            $result = $action->execute($payRun, request()->user());
        } catch (\Throwable $e) {
            return back()->withErrors(['pay_run' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => "გამოთვლილია {$result->linesCreated} ხაზი.".($result->warnings === [] ? '' : ' გაფრთხილებები: '.implode(' ', $result->warnings)),
        ]);
    }

    public function review(PayRunVersionActionRequest $request, PayRun $payRun, ReviewPayRunAction $action): RedirectResponse
    {
        $this->authorize('review', $payRun);

        $action->execute($payRun, $request->user(), (int) $request->validated('version'), $request->validated('reason'));

        return back()->with('toast', ['type' => 'success', 'message' => 'გადახედილია.']);
    }

    public function approve(PayRunVersionActionRequest $request, PayRun $payRun, ApprovePayRunAction $action): RedirectResponse
    {
        $this->authorize('approve', $payRun);

        try {
            $action->execute($payRun, $request->user(), (int) $request->validated('version'), $request->validated('reason'));
        } catch (\Throwable $e) {
            return back()->withErrors(['pay_run' => $e->getMessage()]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'დამტკიცებულია.']);
    }

    public function lock(PayRunVersionActionRequest $request, PayRun $payRun, LockPayRunAction $action): RedirectResponse
    {
        $this->authorize('lock', $payRun);

        $action->execute($payRun, $request->user(), (int) $request->validated('version'));

        return back()->with('toast', ['type' => 'success', 'message' => 'დაიხურა.']);
    }
}
