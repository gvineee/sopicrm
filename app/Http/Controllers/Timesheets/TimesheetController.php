<?php

namespace App\Http\Controllers\Timesheets;

use App\Domain\Attendance\Models\Timesheet;
use App\Domain\Employees\Models\Employee;
use App\Domain\Payroll\Models\PayPeriod;
use App\Domain\Timesheets\Actions\ApproveTimesheetAction;
use App\Domain\Timesheets\Actions\CancelTimesheetEmailBatchAction;
use App\Domain\Timesheets\Actions\CreateTimesheetEmailBatchAction;
use App\Domain\Timesheets\Actions\GenerateTimesheetForPayPeriodAction;
use App\Domain\Timesheets\Actions\GenerateTimesheetPdfAction;
use App\Domain\Timesheets\Actions\LockTimesheetAction;
use App\Domain\Timesheets\Actions\RejectTimesheetAction;
use App\Domain\Timesheets\Actions\RetryTimesheetEmailBatchDeliveryAction;
use App\Domain\Timesheets\Actions\SendTimesheetEmailAction;
use App\Domain\Timesheets\Actions\SubmitTimesheetAction;
use App\Domain\Timesheets\Models\TimesheetEmailBatch;
use App\Domain\Timesheets\Models\TimesheetEmailDelivery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Timesheets\ApproveTimesheetRequest;
use App\Http\Requests\Timesheets\CreateTimesheetEmailBatchRequest;
use App\Http\Requests\Timesheets\GenerateTimesheetRequest;
use App\Http\Requests\Timesheets\RejectTimesheetRequest;
use App\Http\Requests\Timesheets\SendTimesheetEmailRequest;
use App\Http\Requests\Timesheets\TimesheetVersionActionRequest;
use App\Http\Resources\Timesheets\TimesheetEmailBatchResource;
use App\Http\Resources\Timesheets\TimesheetEmailDeliveryResource;
use App\Http\Resources\Timesheets\TimesheetResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
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
            'canSendBatch' => $request->user()->can('sendBatch', Timesheet::class),
            'filteredTotal' => $timesheets->total(),
            'filters' => [
                'employee_id' => $request->string('employee_id')->toString() ?: null,
                'status' => $request->string('status')->toString() ?: null,
            ],
        ]);
    }

    /**
     * TIMESHEET-EMAIL-02: recent batches for the history panel on the Index
     * page — org-scoped by the model's own tenant scope, no per-batch
     * Policy check needed beyond the class-level `sendBatch` gate (same
     * reasoning TimesheetPolicy::sendBatch's own docblock states).
     */
    public function emailBatchIndex(Request $request): JsonResponse
    {
        $this->authorize('sendBatch', Timesheet::class);

        $batches = TimesheetEmailBatch::query()
            ->withCount('deliveries')
            ->with(['deliveries', 'requestedBy'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json(['batches' => TimesheetEmailBatchResource::collection($batches)]);
    }

    /**
     * TIMESHEET-EMAIL-02 max bundle size — the ticket's own "დიდ
     * attachment-ებზე განსაზღვრე ზღვარი" (define a limit for large
     * attachment sets). Applies to both explicit multi-page selection and
     * "select all filtered".
     */
    private const MAX_BATCH_SIZE = 200;

    public function emailBatchStore(CreateTimesheetEmailBatchRequest $request, CreateTimesheetEmailBatchAction $action): RedirectResponse
    {
        $this->authorize('sendBatch', Timesheet::class);

        $timesheetIds = $request->boolean('select_all_filtered')
            ? $this->resolveFilteredTimesheetIds($request)
            : $request->validated('timesheet_ids');

        if (count($timesheetIds) > self::MAX_BATCH_SIZE) {
            return back()->withErrors([
                'timesheet_ids' => 'ერთ გაგზავნაში მაქსიმუმ '.self::MAX_BATCH_SIZE.' ტაბელია დაშვებული (მონიშნულია '.count($timesheetIds).').',
            ]);
        }

        $batch = $action->execute(
            $timesheetIds,
            (string) $request->validated('mode'),
            $request->validated('bundled_recipient_email'),
            $request->validated('bundled_recipient_user_id'),
            $request->user(),
        );

        $skippedCount = count($batch->skipped_details ?? []);
        $message = $skippedCount > 0
            ? "გაგზავნა რიგშია — {$skippedCount} ტაბელი გამოტოვებულია (იხილეთ დეტალები)."
            : 'გაგზავნა რიგშია.';

        return back()->with('toast', ['type' => $skippedCount > 0 ? 'warning' : 'success', 'message' => $message]);
    }

    /**
     * TIMESHEET-EMAIL-02 "select all N filtered": re-runs the SAME filter
     * predicate index() uses, fresh, at commit time — this is what pins the
     * concrete dataset the ticket requires (a list change between the user
     * clicking "select all" and this request landing is impossible to
     * observe from here; the set is whatever matches right now, capped at
     * MAX_BATCH_SIZE).
     *
     * @return list<string>
     */
    private function resolveFilteredTimesheetIds(Request $request): array
    {
        $employeeId = $request->input('filters.employee_id');
        $status = $request->input('filters.status');

        $ids = Timesheet::query()
            ->when($employeeId, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->limit(self::MAX_BATCH_SIZE + 1)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        return array_values($ids);
    }

    public function emailBatchShow(TimesheetEmailBatch $batch): JsonResponse
    {
        $this->authorize('sendBatch', Timesheet::class);

        $batch->load(['deliveries' => fn ($q) => $q->withCount('items')->with('requestedBy')]);

        return response()->json(['batch' => new TimesheetEmailBatchResource($batch)]);
    }

    public function emailBatchCancel(TimesheetEmailBatch $batch, CancelTimesheetEmailBatchAction $action): RedirectResponse
    {
        $this->authorize('sendBatch', Timesheet::class);

        $action->execute($batch, request()->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'დარჩენილი გაგზავნები გაუქმდა.']);
    }

    public function emailBatchDeliveryRetry(
        TimesheetEmailBatch $batch,
        TimesheetEmailDelivery $delivery,
        RetryTimesheetEmailBatchDeliveryAction $action,
    ): RedirectResponse {
        $this->authorize('sendBatch', Timesheet::class);
        abort_unless($delivery->batch_id === $batch->id, 404);

        $action->execute($delivery, request()->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'ხელახლა გაგზავნა რიგშია.']);
    }

    public function show(Timesheet $timesheet): Response
    {
        $this->authorize('view', $timesheet);

        $timesheet->load(['employee', 'payPeriod', 'lines.project']);
        $canSend = auth()->user()?->can('send', $timesheet) ?? false;

        return Inertia::render('Timesheets/Show', [
            'timesheet' => new TimesheetResource($timesheet),
            'canSubmit' => auth()->user()?->can('submit', $timesheet) ?? false,
            'canApprove' => auth()->user()?->can('approve', $timesheet) ?? false,
            'canLock' => auth()->user()?->can('lock', $timesheet) ?? false,
            'canSend' => $canSend,
            'emailDeliveries' => $canSend
                ? TimesheetEmailDeliveryResource::collection(
                    $timesheet->emailDeliveries()->with('requestedBy')->orderByDesc('created_at')->get()
                )
                : [],
            'defaultRecipientEmail' => $canSend ? $timesheet->employee->user?->email : null,
        ]);
    }

    /**
     * TIMESHEET-EMAIL-01: "recipient/subject preview" — computes the
     * default recipient/subject without sending anything. The actual send
     * is a separate, explicit POST (emailSend below).
     */
    public function emailPreview(Timesheet $timesheet): JsonResponse
    {
        $this->authorize('send', $timesheet);

        $timesheet->load(['employee', 'payPeriod']);
        $employeeName = trim($timesheet->employee->first_name.' '.$timesheet->employee->last_name);

        return response()->json([
            'recipient_email' => $timesheet->employee->user?->email,
            'recipient_user_id' => $timesheet->employee->user?->id,
            'subject' => "თქვენი ტაბელი — {$timesheet->payPeriod->starts_on->toDateString()} — {$timesheet->payPeriod->ends_on->toDateString()}",
            'employee_name' => $employeeName,
        ]);
    }

    public function emailSend(SendTimesheetEmailRequest $request, Timesheet $timesheet, SendTimesheetEmailAction $action): RedirectResponse
    {
        $this->authorize('send', $timesheet);

        $action->send(
            $timesheet,
            (string) $request->validated('recipient_email'),
            $request->validated('recipient_user_id'),
            (string) $request->validated('subject'),
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'ტაბელის გაგზავნა რიგშია.']);
    }

    public function emailRetry(Request $request, Timesheet $timesheet, TimesheetEmailDelivery $delivery, SendTimesheetEmailAction $action): RedirectResponse
    {
        $this->authorize('send', $timesheet);
        abort_unless($delivery->timesheet_id === $timesheet->id, 404);

        $action->retry($delivery, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'ხელახლა გაგზავნა რიგშია.']);
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
