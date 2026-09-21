<?php

namespace App\Http\Controllers\Contractors;

use App\Domain\Contractors\Actions\ApproveContractorContractAction;
use App\Domain\Contractors\Actions\CloseContractorContractAction;
use App\Domain\Contractors\Actions\CreateContractorContractAction;
use App\Domain\Contractors\Actions\RejectContractorContractAction;
use App\Domain\Contractors\Actions\SubmitContractorContractForApprovalAction;
use App\Domain\Contractors\Models\Contractor;
use App\Domain\Contractors\Models\ContractorAct;
use App\Domain\Contractors\Models\ContractorContract;
use App\Domain\Contractors\Models\ContractorPayment;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Models\Attachment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contractors\ContractorContractRequest;
use App\Http\Requests\Contractors\RejectContractorContractRequest;
use App\Http\Resources\Contractors\ContractorActResource;
use App\Http\Resources\Contractors\ContractorContractResource;
use App\Http\Resources\Contractors\ContractorPaymentResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ContractorContractController extends Controller
{
    use AuthorizesRequests;

    public function store(ContractorContractRequest $request, Contractor $contractor, CreateContractorContractAction $action): RedirectResponse
    {
        $this->authorize('create', ContractorContract::class);

        $contract = $action->execute($contractor, $request->contractData(), $request->user());

        return to_route('contractors.contracts.show', [$contractor, $contract])->with('toast', [
            'type' => 'success', 'message' => 'კონტრაქტი შეიქმნა.',
        ]);
    }

    public function show(Request $request, Contractor $contractor, ContractorContract $contract): Response
    {
        $this->authorize('view', $contract);

        $contract->load(['project', 'submittedBy', 'approvedBy']);
        $contract->load(['acts.contractor', 'acts.submittedBy', 'acts.task', 'acts.acceptance', 'payments.recordedBy']);

        $pendingEvidence = Attachment::query()
            ->where('owner_type', Contractor::class)
            ->where('owner_id', $contractor->id)
            ->where('status', 'available')
            ->orderByDesc('created_at')
            ->get(['id', 'original_filename', 'mime_type', 'caption']);

        return Inertia::render('Contractors/Contracts/Show', [
            'contractor' => ['id' => $contractor->id, 'name' => $contractor->name],
            'contract' => new ContractorContractResource($contract),
            'acts' => ContractorActResource::collection($contract->acts),
            'payments' => ContractorPaymentResource::collection($contract->payments),
            'pendingEvidence' => $pendingEvidence,
            'projects' => Project::query()->orderBy('name')->get(['id', 'name']),
            'canManage' => $request->user()->can('update', $contract),
            'canApprove' => $request->user()->can('approve', $contract),
            // UI-visibility hint only — the real authorization boundary is
            // ContractorActController::store's $this->authorize('submit', [...])
            // against whichever project is actually chosen in the form.
            'canSubmitAct' => $contract->project !== null
                ? $request->user()->can('submit', [ContractorAct::class, $contract->project])
                : $request->user()->can('contractors.acts.submit'),
            'canRecordPayment' => $request->user()->can('create', [ContractorPayment::class, $contract]),
        ]);
    }

    public function update(ContractorContractRequest $request, Contractor $contractor, ContractorContract $contract): RedirectResponse
    {
        $this->authorize('update', $contract);

        if ($contract->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => 'რედაქტირება შესაძლებელია მხოლოდ draft სტატუსში.',
            ]);
        }

        $contract->update($request->contractData());

        return back()->with('toast', ['type' => 'success', 'message' => 'კონტრაქტი განახლდა.']);
    }

    public function submitForApproval(Request $request, Contractor $contractor, ContractorContract $contract, SubmitContractorContractForApprovalAction $action): RedirectResponse
    {
        $this->authorize('submitForApproval', $contract);

        $action->execute($contract, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'კონტრაქტი გაიგზავნა დასამტკიცებლად.']);
    }

    public function approve(Request $request, Contractor $contractor, ContractorContract $contract, ApproveContractorContractAction $action): RedirectResponse
    {
        $this->authorize('approve', $contract);

        $action->execute($contract, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'კონტრაქტი დამტკიცდა.']);
    }

    public function reject(RejectContractorContractRequest $request, Contractor $contractor, ContractorContract $contract, RejectContractorContractAction $action): RedirectResponse
    {
        $this->authorize('reject', $contract);

        $action->execute($contract, $request->user(), (string) $request->validated('reason'));

        return back()->with('toast', ['type' => 'success', 'message' => 'კონტრაქტი უარყოფილია.']);
    }

    public function close(Request $request, Contractor $contractor, ContractorContract $contract, CloseContractorContractAction $action): RedirectResponse
    {
        $this->authorize('close', $contract);

        $action->execute($contract, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'კონტრაქტი დაიხურა.']);
    }
}
