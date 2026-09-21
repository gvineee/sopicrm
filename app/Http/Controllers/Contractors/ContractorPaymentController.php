<?php

namespace App\Http\Controllers\Contractors;

use App\Domain\Contractors\Actions\RecordContractorPaymentAction;
use App\Domain\Contractors\Models\Contractor;
use App\Domain\Contractors\Models\ContractorContract;
use App\Domain\Contractors\Models\ContractorPayment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contractors\ContractorPaymentRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class ContractorPaymentController extends Controller
{
    use AuthorizesRequests;

    public function store(ContractorPaymentRequest $request, Contractor $contractor, ContractorContract $contract, RecordContractorPaymentAction $action): RedirectResponse
    {
        $this->authorize('create', [ContractorPayment::class, $contract]);

        try {
            $action->execute(
                $contractor,
                $contract,
                (string) $request->validated('amount'),
                strtoupper((string) $request->validated('currency')),
                (string) $request->validated('paid_at'),
                $request->validated('method'),
                $request->validated('reference'),
                $request->validated('notes'),
                $request->user(),
                (string) $request->validated('request_id'),
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'გადახდა დაფიქსირდა.']);
    }
}
