<?php

namespace App\Http\Controllers\Timesheets;

use App\Domain\Attendance\Models\AttendanceAdjustment;
use App\Domain\Employees\Models\Employee;
use App\Domain\Timesheets\Actions\DecideAttendanceAdjustmentAction;
use App\Domain\Timesheets\Actions\RequestAttendanceAdjustmentAction;
use App\Domain\Timesheets\DataTransferObjects\ApprovalDecisionData;
use App\Domain\Timesheets\DataTransferObjects\AttendanceAdjustmentRequestData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Timesheets\DecideAttendanceAdjustmentRequest;
use App\Http\Requests\Timesheets\RequestAttendanceAdjustmentRequest;
use App\Http\Resources\Timesheets\AttendanceAdjustmentResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceAdjustmentController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AttendanceAdjustment::class);

        $adjustments = AttendanceAdjustment::query()
            ->with('employee')
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Timesheets/Adjustments/Index', [
            'adjustments' => AttendanceAdjustmentResource::collection($adjustments),
            'employees' => Employee::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'canRequest' => $request->user()->can('request', AttendanceAdjustment::class),
        ]);
    }

    public function store(RequestAttendanceAdjustmentRequest $request, RequestAttendanceAdjustmentAction $action): RedirectResponse
    {
        $this->authorize('request', AttendanceAdjustment::class);

        $correctedIn = $request->validated('corrected_clock_in_at');
        $correctedOut = $request->validated('corrected_clock_out_at');

        try {
            $action->handle(new AttendanceAdjustmentRequestData(
                employeeId: (string) $request->validated('employee_id'),
                workDate: (string) $request->validated('work_date'),
                siteId: $request->validated('site_id'),
                correctedClockInAt: is_string($correctedIn) ? Carbon::parse($correctedIn) : null,
                correctedClockOutAt: is_string($correctedOut) ? Carbon::parse($correctedOut) : null,
                correctedHours: $request->validated('corrected_hours') !== null ? (string) $request->validated('corrected_hours') : null,
                reason: (string) $request->validated('reason'),
                evidenceAttachmentId: $request->validated('evidence_attachment_id'),
                requestedByUserId: $request->user()->id,
                originalSessionId: $request->validated('original_session_id'),
            ));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'შესწორების მოთხოვნა გაიგზავნა.']);
    }

    public function decide(DecideAttendanceAdjustmentRequest $request, AttendanceAdjustment $adjustment, DecideAttendanceAdjustmentAction $action): RedirectResponse
    {
        $this->authorize('decide', $adjustment);

        try {
            $action->handle($adjustment, new ApprovalDecisionData(
                approverUserId: $request->user()->id,
                targetVersion: (int) $request->validated('version'),
                decision: (string) $request->validated('decision'),
                reason: $request->validated('reason'),
                ownerSelfApprovalExceptionAcknowledged: (bool) $request->boolean('owner_self_approval_exception_acknowledged'),
            ), $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'გადაწყვეტილება მიღებულია.']);
    }
}
