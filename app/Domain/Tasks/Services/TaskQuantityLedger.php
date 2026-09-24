<?php

namespace App\Domain\Tasks\Services;

use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskAcceptanceLedgerEntry;
use App\Domain\Tasks\Models\TaskSubmission;
use App\Domain\Tasks\Support\Decimal;

/**
 * 03-Construction-Task-Manager-Spec-KA.md §8, implemented literally:
 *
 *     0 < newly_submitted_quantity <= currently_available_scope
 *     0 <= accepted_quantity       <= newly_submitted_quantity
 *     rejected_quantity            =  newly_submitted_quantity - accepted_quantity
 *     net_accepted                 =  sum(valid_acceptances) - sum(authorized_reversals)
 *     net_accepted                <=  baseline_quantity + approved_scope_changes
 *
 * The number a submitter types is a DELTA — what they did this time — not a
 * running total. §8's own worked example is the test: a 100 m² task where 40
 * is submitted, 35 accepted and 5 returned sits at 35; re-submitting and
 * accepting the returned 5 brings it to 40, NOT 45, because the returned
 * volume never left the available scope in the first place. That falls out
 * of computing available scope as `planned - net_accepted - pending`, where
 * a returned submission contributes nothing to either term.
 *
 * `approved_scope_changes` is 0 here: this codebase models no approved
 * scope-change entity, and inventing one would be worse than being explicit
 * that the ceiling is the task's own `planned_quantity` until one exists.
 */
class TaskQuantityLedger
{
    /**
     * Sum of every acceptance minus every authorized reversal, as a
     * 2-decimal numeric string. This is the ONLY source of truth for
     * `tasks.accepted_quantity`, which is a cache of it.
     *
     * @return numeric-string
     */
    public function netAccepted(Task $task): string
    {
        return Decimal::of(
            TaskAcceptanceLedgerEntry::query()->where('task_id', $task->id)->sum('quantity_delta')
        );
    }

    /**
     * Volume already offered and still awaiting a decision. It is reserved:
     * a second submission may not re-offer it, or two pending submissions
     * could together be accepted past the plan.
     *
     * @return numeric-string
     */
    public function pendingSubmitted(Task $task, ?string $excludingSubmissionId = null): string
    {
        $query = TaskSubmission::query()
            ->where('task_id', $task->id)
            ->where('status', 'pending_review');

        if ($excludingSubmissionId !== null) {
            $query->whereKeyNot($excludingSubmissionId);
        }

        return Decimal::of($query->sum('submitted_quantity'));
    }

    /**
     * `currently_available_scope` — what may still be offered right now.
     * Null when the task carries no planned quantity at all, which means
     * "unquantified": such a task is accepted as a yes/no completion and has
     * no ceiling to check against.
     *
     * @return numeric-string|null
     */
    public function availableScope(Task $task, ?string $excludingSubmissionId = null): ?string
    {
        if ($task->planned_quantity === null) {
            return null;
        }

        $remaining = bcsub(
            bcsub(Decimal::of($task->planned_quantity), $this->netAccepted($task), 2),
            $this->pendingSubmitted($task, $excludingSubmissionId),
            2,
        );

        return bccomp($remaining, '0', 2) === -1 ? '0.00' : $remaining;
    }

    /**
     * Whether accepting `$delta` more would complete the task. An
     * unquantified task completes on its first acceptance; a quantified one
     * completes only when the net accepted volume reaches the plan —
     * accepting 30 of 100 m² is progress, not completion (TM-05).
     */
    public function completesTask(Task $task, string $netAcceptedAfter): bool
    {
        if ($task->planned_quantity === null || bccomp(Decimal::of($task->planned_quantity), '0', 2) !== 1) {
            return true;
        }

        return bccomp(Decimal::of($netAcceptedAfter), Decimal::of($task->planned_quantity), 2) >= 0;
    }
}
