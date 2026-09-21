<?php

namespace App\Domain\Contractors\Actions;

use App\Domain\Contractors\Models\Contractor;
use App\Domain\Contractors\Models\ContractorAct;
use App\Domain\Contractors\Models\ContractorContract;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Models\Attachment;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mirrors App\Domain\Tasks\Actions\SubmitTaskForAcceptance: entered by our
 * own staff on the contractor's behalf (no contractor-facing login exists —
 * see plan Context), every validation below runs and throws before any row
 * is written.
 */
class SubmitContractorAct
{
    /**
     * @param  list<string>  $attachmentIds  IDs of Attachment rows already
     *                                       uploaded and owned by this Contractor
     *                                       (owner_type = Contractor::class)
     *                                       offered as evidence for this act.
     */
    public function execute(
        Contractor $contractor,
        ContractorContract $contract,
        Project $project,
        ?Task $task,
        User $submittedBy,
        ?string $description,
        ?string $quantity,
        array $attachmentIds,
    ): ContractorAct {
        if ($contract->contractor_id !== $contractor->id) {
            throw ValidationException::withMessages([
                'contract_id' => 'მითითებული კონტრაქტი არ ეკუთვნის ამ კონტრაქტორს.',
            ]);
        }

        if ($contract->status !== 'active') {
            throw ValidationException::withMessages([
                'contract_id' => 'აქტის გაგზავნა შესაძლებელია მხოლოდ active კონტრაქტზე.',
            ]);
        }

        if ($contract->project_id !== null && $contract->project_id !== $project->id) {
            throw ValidationException::withMessages([
                'contract_id' => 'მითითებული კონტრაქტი სხვა პროექტზეა შეზღუდული.',
            ]);
        }

        if ($task !== null && $task->project_id !== $project->id) {
            throw ValidationException::withMessages([
                'task_id' => 'მითითებული დავალება ამ პროექტს არ ეკუთვნის.',
            ]);
        }

        $attachments = Attachment::query()
            ->where('owner_type', Contractor::class)
            ->where('owner_id', $contractor->id)
            ->whereIn('id', $attachmentIds)
            ->get();

        if ($attachments->count() !== count(array_unique($attachmentIds))) {
            throw ValidationException::withMessages([
                'attachments' => 'ერთი ან მეტი მითითებული ფაილი ვერ მოიძებნა ამ კონტრაქტორზე.',
            ]);
        }

        $notAvailable = $attachments->firstWhere('status', '!=', 'available');
        if ($notAvailable !== null) {
            throw ValidationException::withMessages([
                'attachments' => 'ერთი ან მეტი ფოტო/ფაილი ჯერ არ არის სრულად ატვირთული ან ატვირთვა ჩაიშალა.',
            ]);
        }

        if ($quantity !== null && ! is_numeric($quantity)) {
            throw ValidationException::withMessages([
                'quantity' => 'მოცულობა უნდა იყოს რიცხვი.',
            ]);
        }

        return DB::transaction(function () use ($contractor, $contract, $project, $task, $submittedBy, $description, $quantity, $attachments) {
            $act = ContractorAct::create([
                'contractor_id' => $contractor->id,
                'contract_id' => $contract->id,
                'project_id' => $project->id,
                'task_id' => $task?->id,
                'submitted_by_user_id' => $submittedBy->id,
                'description' => $description,
                'quantity' => $quantity,
                'evidence_attachment_ids' => $attachments->pluck('id')->values()->all(),
                'submitted_at' => now(),
                'status' => 'pending_review',
            ]);

            Attachment::query()
                ->whereIn('id', $attachments->pluck('id'))
                ->update(['owner_type' => ContractorAct::class, 'owner_id' => $act->id]);

            return $act->fresh();
        });
    }
}
