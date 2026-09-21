<?php

namespace App\Domain\Companies\Actions;

use App\Domain\Companies\Models\Company;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteCompanyAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(Company $company, User $actor): void
    {
        if (Company::query()->where('organization_id', $company->organization_id)->count() <= 1) {
            throw ValidationException::withMessages([
                'company' => 'ორგანიზაციას უნდა ჰქონდეს მინიმუმ ერთი კომპანია — ბოლო კომპანიის წაშლა შეუძლებელია.',
            ]);
        }

        DB::transaction(function () use ($company, $actor): void {
            $before = $company->only(['name', 'legal_name', 'code', 'default_currency', 'default_timezone', 'is_active']);

            // Projects/memberships/users' current_company_id are handled by
            // the DB's own nullOnDelete/cascade FKs (see the companies
            // migration) — the one gap those FKs can't cover is leaving the
            // *acting* user stranded with no active company mid-session.
            if ($actor->current_company_id === $company->id) {
                $replacement = Company::query()
                    ->where('organization_id', $company->organization_id)
                    ->whereKeyNot($company->id)
                    ->orderBy('created_at')
                    ->first();

                if ($replacement !== null) {
                    $actor->current_company_id = $replacement->id;
                    $actor->save();
                }
            }

            $company->delete();

            $this->audit->log(
                action: 'companies.company.deleted',
                target: $company,
                before: $before,
                actor: $actor,
            );
        });
    }
}
