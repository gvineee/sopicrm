<?php

namespace App\Http\Controllers\Timesheets;

use App\Domain\Attendance\Models\Timesheet;
use App\Domain\Employees\Models\Employee;
use App\Domain\Payroll\Models\PayPeriod;
use App\Domain\Timesheets\Actions\ApproveTimesheetAction;
use App\Domain\Timesheets\Actions\GenerateTimesheetForPayPeriodAction;
use App\Domain\Timesheets\Actions\GenerateTimesheetPdfAction;
use App\Domain\Timesheets\Actions\LockTimesheetAction;
use App\Domain\Timesheets\Actions\RejectTimesheetAction;
use App\Domain\Timesheets\Actions\SubmitTimesheetAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Timesheets\ApproveTimesheetRequest;
use App\Http\Requests\Timesheets\GenerateTimesheetRequest;
use App\Http\Requests\Timesheets\RejectTimesheetRequest;
use App\Http\Requests\Timesheets\TimesheetVersionActionRequest;
use App\Http\Resources\Timesheets\TimesheetResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TimesheetController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Timesheet::class);

        $timesheets = Timesheet::query()
            ->with(['employee', 'payPeriod'])
            ->withSum('lines', 'payable_minutes')
            ->when($request->string('employee_id')->toString(), fn ($q, $id) => $q->where('employee_id', $id))
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Timesheets/Index', [
            'timesheets' => TimesheetResource::collection($timesheets),
            'employees' => Employee::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'payPeriods' => PayPeriod::query()->orderByDesc('starts_on')->get(['id', 'starts_on', 'ends_on']),
            'canGenerate' => $request->user()->can('generate', Timesheet::class),
            'filters' => [
                'employee_id' => $request->string('employee_id')->toString() ?: null,
                'status' => $request->string('status')->toString() ?: null,
            ],
        ]);
    }

    public function show(Timesheet $timesheet): Response
    {
        $this->authorize('view', $timesheet);

        $timesheet->load(['employee', 'payPeriod', 'lines.project']);

        return Inertia::render('Timesheets/Show', [
            'timesheet' => new TimesheetResource($timesheet),
            'canSubmit' => auth()->user()?->can('submit', $timesheet) ?? false,
            'canApprove' => auth()->user()?->can('approve', $timesheet) ?? false,
            'canLock' => auth()->user()?->can('lock', $timesheet) ?? false,
        ]);
    }

    /**
     * TIMESHEET-01: on-demand PDF snapshot, streamed inline (opens in the
     * browser rather than forcing a download) — matches this codebase's
     * existing precedent for previewable protected content
     * (Tasks\TaskController::showAttachment()). Reuses the same `view`
     * ability the HTML detail page already requires; no separate PDF
     * permission exists or is needed.
     */
    public function pdf(Timesheet $timesheet, GenerateTimesheetPdfAction $action): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorize('view', $timesheet);

        $pdf = $action->execute($timesheet);

        return $pdf->stream("tabeli-{$timesheet->id}-v{$timesheet->version}.pdf");
    }

    public function generate(GenerateTimesheetRequest $request, GenerateTimesheetForPayPeriodAction $action): RedirectResponse
    {
        $this->authorize('generate', Timesheet::class);

        $employee = Employee::query()->findOrFail((string) $request->validated('employee_id'));
        $payPeriod = PayPeriod::query()->findOrFail((string) $request->validated('pay_period_id'));

        $timesheet = $action->handle($employee, $payPeriod);

        return to_route('timesheets.show', $timesheet)->with('toast', [
            'type' => 'success',
            'message' => 'ტაბელი გენერირებულია.',
        ]);
    }

    public function submit(TimesheetVersionActionRequest $request, Timesheet $timesheet, SubmitTimesheetAction $action): RedirectResponse
    {
        $this->authorize('submit', $timesheet);

        try {
            $action->handle($timesheet, $request->user(), (int) $request->validated('version'));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'ტაბელი გაიგზავნა განსახილველად.']);
    }

    public function approve(ApproveTimesheetRequest $request, Timesheet $timesheet, ApproveTimesheetAction $action): RedirectResponse
    {
        $this->authorize('approve', $timesheet);

        try {
            $action->handle(
                $timesheet,
                $request->user(),
                (int) $request->validated('version'),
                (bool) $request->boolean('owner_self_approval_exception_acknowledged'),
                $request->validated('reason'),
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'ტაბელი დამტკიცებულია.']);
    }

    public function reject(RejectTimesheetRequest $request, Timesheet $timesheet, RejectTimesheetAction $action): RedirectResponse
    {
        $this->authorize('approve', $timesheet);

        $action->handle($timesheet, $request->user(), (int) $request->validated('version'), (string) $request->validated('reason'));

        return back()->with('toast', ['type' => 'success', 'message' => 'ტაბელი დაბრუნებულია.']);
    }

    public function lock(TimesheetVersionActionRequest $request, Timesheet $timesheet, LockTimesheetAction $action): RedirectResponse
    {
        $this->authorize('lock', $timesheet);

        $action->handle($timesheet, $request->user(), (int) $request->validated('version'));

        return back()->with('toast', ['type' => 'success', 'message' => 'ტაბელი დაიხურა.']);
    }
}
