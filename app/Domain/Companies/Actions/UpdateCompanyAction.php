<?php

namespace App\Domain\Companies\Actions;

use App\Domain\Companies\Models\Company;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;

class UpdateCompanyAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, legal_name: string|null, code: string|null, default_currency: string, default_timezone: string, is_active: bool}  $data
     */
    public function execute(Company $company, array $data, User $actor): Company
    {
        $before = $company->only(array_keys($data));
        $company->update($data);

        $this->audit->log(
            action: 'companies.company.updated',
            target: $company,
            before: $before,
            after: $company->only(array_keys($data)),
            actor: $actor,
        );

        return $company->refresh();
    }
}
