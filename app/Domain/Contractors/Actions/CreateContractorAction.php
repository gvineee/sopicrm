<?php

namespace App\Domain\Contractors\Actions;

use App\Domain\Contractors\Models\Contractor;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;

class CreateContractorAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, legal_name: string|null, tax_id: string|null, contact_person: string|null, phone: string|null, email: string|null, default_currency: string, is_active: bool, notes: string|null}  $data
     */
    public function execute(array $data, User $actor): Contractor
    {
        $contractor = Contractor::query()->create($data);

        $this->audit->log(
            action: 'contractors.contractor.created',
            target: $contractor,
            after: $contractor->only(array_keys($data)),
            actor: $actor,
        );

        return $contractor;
    }
}
