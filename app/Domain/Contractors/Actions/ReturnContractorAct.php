<?php

namespace App\Domain\Contractors\Actions;

use App\Domain\Contractors\Models\ContractorAct;
use App\Domain\Shared\Services\AuditLogger;
use App\Domain\Tasks\Models\Comment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mirrors App\Domain\Tasks\Actions\ReturnTaskSubmission: terminal for this
 * act row — a corrected act is a brand-new ContractorAct, never a reopened
 * one, so an already-returned act can never quietly become acceptable again.
 */
class ReturnContractorAct
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(ContractorAct $act, User $reviewer, string $reason): ContractorAct
    {
        if ($act->status !== 'pending_review') {
            throw ValidationException::withMessages([
                'status' => 'მხოლოდ განსახილველი (pending_review) აქტის დაბრუნებაა შესაძლებელი.',
            ]);
        }

        return DB::transaction(function () use ($act, $reviewer, $reason) {
            $act->update(['status' => 'returned', 'returned_reason' => $reason]);

            Comment::create([
                'commentable_type' => $act->getMorphClass(),
                'commentable_id' => $act->id,
                'author_user_id' => $reviewer->id,
                'body' => $reason,
                'mentions' => [],
            ]);

            $this->audit->log(
                action: 'contractors.act.returned',
                target: $act,
                reason: $reason,
                actor: $reviewer,
            );

            return $act->fresh();
        });
    }
}
