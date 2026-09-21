<?php

namespace App\Domain\Companies\Actions;

use App\Domain\Companies\Models\Company;
use App\Domain\Companies\Models\CompanyMembership;
use App\Domain\Shared\Services\AuditLogger;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCompanyAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, legal_name: string|null, code: string|null, default_currency: string, default_timezone: string, is_active: bool}  $data
     */
    public function execute(array $data, User $actor): Company
    {
        CurrentOrganization::set(filled($actor->current_organization_id)
            ? $actor->current_organization_id
            : $actor->organization_id);
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("select set_config('app.current_org_id', ?, false)", [CurrentOrganization::requireId()]);
        }

        if ($data['code'] !== null && Company::query()->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages([
                'code' => 'ეს კოდი ამ ორგანიზაციაში უკვე გამოყენებულია.',
            ]);
        }

        try {
            return DB::transaction(function () use ($data, $actor): Company {
                $company = Company::query()->create($data);
                $hasMembership = CompanyMembership::query()->where('user_id', $actor->id)->exists();

                CompanyMembership::query()->create([
                    'company_id' => $company->id,
                    'user_id' => $actor->id,
                    'is_primary' => ! $hasMembership,
                ]);

                if ($actor->current_company_id === null) {
                    $actor->current_company_id = $company->id;
                    $actor->save();
                }

                $this->audit->log(
                    action: 'companies.company.created',
                    target: $company,
                    after: $company->only(['name', 'legal_name', 'code', 'default_currency', 'default_timezone', 'is_active']),
                    actor: $actor,
                );

                return $company;
            });
        } catch (UniqueConstraintViolationException $e) {
            // The exists()-based check above is a best-effort UX shortcut,
            // not the enforcement boundary — it can't close a race between
            // two near-simultaneous submissions (e.g. a fast double-click).
            // The DB-level unique(organization_id, code) constraint is the
            // real guarantee; without this catch, a race here surfaced as an
            // uncaught 500 that knocked the user out of the Inertia SPA
            // entirely instead of showing a normal validation error.
            throw ValidationException::withMessages([
                'code' => 'ეს კოდი ამ ორგანიზაციაში უკვე გამოყენებულია.',
            ]);
        }
    }
}
