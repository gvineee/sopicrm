<?php

namespace App\Http\Controllers\Payroll;

use App\Domain\Employees\Models\Employee;
use App\Domain\Payroll\Actions\RecordPaymentAction;
use App\Domain\Payroll\Exceptions\PayrollDomainException;
use App\Domain\Payroll\Models\Payment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\RecordPaymentRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    use AuthorizesRequests;

    public function store(RecordPaymentRequest $request, RecordPaymentAction $action): RedirectResponse
    {
        $this->authorize('create', Payment::class);

        $employee = Employee::query()->findOrFail((string) $request->validated('employee_id'));

        try {
            $action->execute(
                $employee,
                (string) $request->validated('amount'),
                strtoupper((string) $request->validated('currency')),
                (string) $request->validated('method'),
                $request->validated('reference'),
                $request->validated('evidence_attachment_id'),
                $request->user(),
                $request->validated('deducts_advance_id'),
                (string) $request->validated('request_id'),
            );
        } catch (PayrollDomainException $e) {
            throw ValidationException::withMessages($e->fieldErrors());
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['amount' => [$e->getMessage()]]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'გადახდა დაფიქსირდა.']);
    }
}
