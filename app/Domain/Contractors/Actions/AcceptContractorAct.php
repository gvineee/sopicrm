<?php

namespace App\Domain\Contractors\Actions;

use App\Domain\Contractors\Models\ContractorAct;
use App\Domain\Contractors\Models\ContractorActAcceptance;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mirrors App\Domain\Tasks\Actions\AcceptTaskSubmission. Hard rule: the
 * `contractor_act_id` unique constraint on `contractor_act_acceptances` makes
 * double-accepting the same act impossible at the DB layer — this Action's
 * `acceptance()->exists()` check is the friendly pre-check, not the real
 * guarantee. Accepting an act never touches the referenced Task's own
 * status/accepted_quantity (see plan Context) — it is purely a
 * contractor-billing artifact.
 */
class AcceptContractorAct
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(ContractorAct $act, User $acceptedBy, ?string $acceptedQuantity, string $acceptedAmount, ?string $notes): ContractorActAcceptance
    {
        if ($act->status !== 'pending_review') {
            throw ValidationException::withMessages([
                'status' => 'მხოლოდ განსახილველი (pending_review) აქტის მიღებაა შესაძლებელი.',
            ]);
        }

        if ($act->acceptance()->exists()) {
            throw ValidationException::withMessages([
                'status' => 'ეს აქტი უკვე მიღებულია.',
            ]);
        }

        if (! is_numeric($acceptedAmount) || bccomp($acceptedAmount, '0', 2) <= 0) {
            throw ValidationException::withMessages([
                'accepted_amount' => 'მიღებული თანხა უნდა იყოს დადებითი რიცხვი.',
            ]);
        }

        return DB::transaction(function () use ($act, $acceptedBy, $acceptedQuantity, $acceptedAmount, $notes) {
            $acceptance = ContractorActAcceptance::create([
                'contractor_act_id' => $act->id,
                'accepted_by_user_id' => $acceptedBy->id,
                'accepted_quantity' => $acceptedQuantity,
                'accepted_amount' => $acceptedAmount,
                'accepted_at' => now(),
                'notes' => $notes,
            ]);

            $act->update(['status' => 'accepted']);

            $this->audit->log(
                action: 'contractors.act.accepted',
                target: $act,
                after: ['accepted_amount' => $acceptedAmount, 'accepted_quantity' => $acceptedQuantity],
                actor: $acceptedBy,
            );

            return $acceptance;
        });
    }
}
