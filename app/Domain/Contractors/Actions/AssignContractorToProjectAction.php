<?php

namespace App\Domain\Contractors\Actions;

use App\Domain\Contractors\Models\Contractor;
use App\Domain\Contractors\Models\ContractorContract;
use App\Domain\Contractors\Models\ContractorProjectAssignment;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AssignContractorToProjectAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(
        Contractor $contractor,
        Project $project,
        ?ContractorContract $contract,
        string $startsOn,
        ?string $endsOn,
        ?string $scopeDescription,
        User $actor,
    ): ContractorProjectAssignment {
        if ($contract !== null) {
            $this->assertUsableContract($contract, $contractor, $project);
        }

        $assignment = ContractorProjectAssignment::query()->create([
            'contractor_id' => $contractor->id,
            'project_id' => $project->id,
            'contract_id' => $contract?->id,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'scope_description' => $scopeDescription,
        ]);

        $this->audit->log(
            action: 'contractors.project_assignment.created',
            target: $assignment,
            after: $assignment->only(['contractor_id', 'project_id', 'contract_id', 'starts_on', 'ends_on']),
            actor: $actor,
        );

        return $assignment;
    }

    private function assertUsableContract(ContractorContract $contract, Contractor $contractor, Project $project): void
    {
        if ($contract->contractor_id !== $contractor->id) {
            throw ValidationException::withMessages([
                'contract_id' => 'მითითებული კონტრაქტი არ ეკუთვნის ამ კონტრაქტორს.',
            ]);
        }

        if ($contract->status !== 'active') {
            throw ValidationException::withMessages([
                'contract_id' => 'კონტრაქტორის მინიჭება შესაძლებელია მხოლოდ active კონტრაქტით.',
            ]);
        }

        if ($contract->project_id !== null && $contract->project_id !== $project->id) {
            throw ValidationException::withMessages([
                'contract_id' => 'მითითებული კონტრაქტი სხვა პროექტზეა შეზღუდული.',
            ]);
        }
    }
}
