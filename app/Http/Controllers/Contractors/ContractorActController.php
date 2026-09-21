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
use App\Domain\Shared\Models\Attachment;
use App\Domain\Tasks\Models\Task;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contractors\AcceptContractorActRequest;
use App\Http\Requests\Contractors\ReturnContractorActRequest;
use App\Http\Requests\Contractors\SubmitContractorActRequest;
use App\Http\Requests\Contractors\UploadContractorAttachmentRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * FILES-01 (deferred remainder): protected inline preview/download for
     * one of an act's own evidence attachments — mirrors
     * App\Http\Controllers\Tasks\TaskController::showAttachment()'s exact
     * ownership-check shape. Evidence attachments are owned by the
     * `Contractor` (see App\Domain\Contractors\Actions\UploadContractorAttachment),
     * not the act itself — the real link is the act's own
     * `evidence_attachment_ids` array, so `abort_unless` checks THAT
     * (plus the attachment's own owner still being this contractor) rather
     * than a direct owner_type/owner_id match against the act, which would
     * never be true by this domain's own design.
     */
    public function showAttachment(Contractor $contractor, ContractorAct $act, Attachment $attachment): StreamedResponse
    {
        $this->authorize('view', $act);
        abort_unless($this->attachmentBelongsToAct($attachment, $contractor, $act), 404);
        abort_unless($attachment->status === 'available', 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->storage_path), 404);

        return Storage::disk($attachment->disk)->response($attachment->storage_path, $attachment->original_filename);
    }

    private function attachmentBelongsToAct(Attachment $attachment, Contractor $contractor, ContractorAct $act): bool
    {
        if ($attachment->owner_type !== Contractor::class || $attachment->owner_id !== $contractor->id) {
            return false;
        }

        return in_array($attachment->id, $act->evidence_attachment_ids ?? [], true);
    }
}
