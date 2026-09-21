<?php

namespace App\Domain\Contractors\Actions;

use App\Domain\Contractors\Models\Contractor;
use App\Domain\Contractors\Models\ContractorContract;
use App\Domain\Shared\Services\AuditLogger;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskAssignee;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * A contractor is always an *additional* performer on a task, never the
 * accountable owner — tasks.accountable_owner_employee_id is never touched
 * here, matching the plan's confirmed design.
 */
class AssignContractorToTaskAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(Task $task, Contractor $contractor, ContractorContract $contract, User $actor): TaskAssignee
    {
        if ($contract->contractor_id !== $contractor->id) {
            throw ValidationException::withMessages([
                'contract_id' => 'მითითებული კონტრაქტი არ ეკუთვნის ამ კონტრაქტორს.',
            ]);
        }

        if ($contract->status !== 'active') {
            throw ValidationException::withMessages([
                'contract_id' => 'დავალებაზე მინიჭება შესაძლებელია მხოლოდ active კონტრაქტით.',
            ]);
        }

        if ($contract->project_id !== null && $contract->project_id !== $task->project_id) {
            throw ValidationException::withMessages([
                'contract_id' => 'მითითებული კონტრაქტი სხვა პროექტზეა შეზღუდული.',
            ]);
        }

        $assignee = TaskAssignee::create([
            'task_id' => $task->id,
            'contractor_id' => $contractor->id,
        ]);

        $this->audit->log(
            action: 'contractors.task_assignment.created',
            target: $assignee,
            after: ['task_id' => $task->id, 'contractor_id' => $contractor->id],
            actor: $actor,
        );

        return $assignee;
    }
}
