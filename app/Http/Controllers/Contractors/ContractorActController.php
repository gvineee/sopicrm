<?php

namespace App\Http\Controllers\Contractors;

use App\Domain\Contractors\Actions\AcceptContractorAct;
use App\Domain\Contractors\Actions\ReturnContractorAct;
use App\Domain\Contractors\Actions\SubmitContractorAct;
use App\Domain\Contractors\Actions\UploadContractorAttachment;
use App\Domain\Contractors\Models\Contractor;
use App\Domain\Contractors\Models\ContractorAct;
use App\Domain\Contractors\Models\ContractorContract;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Models\Task;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contractors\AcceptContractorActRequest;
use App\Http\Requests\Contractors\ReturnContractorActRequest;
use App\Http\Requests\Contractors\SubmitContractorActRequest;
use App\Http\Requests\Contractors\UploadContractorAttachmentRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class ContractorActController extends Controller
{
    use AuthorizesRequests;

    public function store(SubmitContractorActRequest $request, Contractor $contractor, SubmitContractorAct $action): RedirectResponse
    {
        $project = Project::query()->findOrFail((string) $request->validated('project_id'));
        $this->authorize('submit', [ContractorAct::class, $project]);

        $contract = ContractorContract::query()->findOrFail((string) $request->validated('contract_id'));
        $taskId = $request->validated('task_id');
        $task = is_string($taskId) ? Task::query()->find($taskId) : null;

        try {
            $act = $action->execute(
                $contractor,
                $contract,
                $project,
                $task,
                $request->user(),
                $request->validated('description'),
                $request->validated('quantity'),
                $request->validated('attachment_ids') ?? [],
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'აქტი გაიგზავნა განსახილველად.']);
    }

    public function accept(AcceptContractorActRequest $request, Contractor $contractor, ContractorAct $act, AcceptContractorAct $action): RedirectResponse
    {
        $this->authorize('accept', $act);

        try {
            $action->execute(
                $act,
                $request->user(),
                $request->validated('accepted_quantity'),
                (string) $request->validated('accepted_amount'),
                $request->validated('notes'),
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'აქტი მიღებულია.']);
    }

    public function returnAct(ReturnContractorActRequest $request, Contractor $contractor, ContractorAct $act, ReturnContractorAct $action): RedirectResponse
    {
        $this->authorize('returnAct', $act);

        $action->execute($act, $request->user(), (string) $request->validated('reason'));

        return back()->with('toast', ['type' => 'success', 'message' => 'აქტი დაბრუნებულია.']);
    }

    public function uploadAttachment(UploadContractorAttachmentRequest $request, Contractor $contractor, UploadContractorAttachment $action): RedirectResponse
    {
        $this->authorize('update', $contractor);

        try {
            $action->execute($contractor, $request->file('file'), $request->user(), $request->validated('caption'));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'ფაილი აიტვირთა.']);
    }
}
