<?php

namespace App\Domain\Contractors\Actions;

use App\Domain\Contractors\Models\Contractor;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;

class UpdateContractorAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, legal_name: string|null, tax_id: string|null, contact_person: string|null, phone: string|null, email: string|null, default_currency: string, is_active: bool, notes: string|null}  $data
     */
    public function execute(Contractor $contractor, array $data, User $actor): Contractor
    {
        $before = $contractor->only(array_keys($data));
        $contractor->update($data);

        $this->audit->log(
            action: 'contractors.contractor.updated',
            target: $contractor,
            before: $before,
            after: $contractor->only(array_keys($data)),
            actor: $actor,
        );

        return $contractor->refresh();
    }
}
