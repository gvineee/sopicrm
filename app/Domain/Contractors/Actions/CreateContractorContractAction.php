<?php

namespace App\Domain\Contractors\Actions;

use App\Domain\Contractors\Models\Contractor;
use App\Domain\Contractors\Models\ContractorContract;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;

class CreateContractorContractAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Contractor $contractor, array $data, User $actor): ContractorContract
    {
        $contract = ContractorContract::query()->create([
            ...$data,
            'contractor_id' => $contractor->id,
            'status' => 'draft',
            'created_by_user_id' => $actor->id,
        ]);

        $this->audit->log(
            action: 'contractors.contract.created',
            target: $contract,
            after: $contract->only(array_keys($data)),
            actor: $actor,
        );

        return $contract;
    }
}
