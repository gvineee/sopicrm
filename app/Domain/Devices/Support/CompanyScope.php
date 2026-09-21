<?php

namespace App\Domain\Devices\Support;

use App\Models\User;

/**
 * TENANT-01: the single shared visibility rule every company-scoped Policy
 * method (DevicePolicy, EmployeePolicy, SitePolicy) calls, so the rule lives
 * in exactly one place rather than being re-derived per module.
 *
 * Rule: a record with a NULL company_id (unmapped) is visible to anyone who
 * could already see it via organization_id alone — company-scoping only
 * ever TIGHTENS visibility once a row is actually assigned, it never hides
 * pre-existing unmapped data the moment this feature landed. A record with
 * a company_id set is visible only to a user whose own current_company_id
 * matches it, UNLESS that user holds an explicitly org-wide role (`owner`/
 * `system_admin` — the same two roles ADMIN-01/ADMIN-02 already treat as
 * org-wide administrators elsewhere in this codebase), who see across every
 * company in their organization by design.
 */
final class CompanyScope
{
    public static function allows(?string $recordCompanyId, User $user): bool
    {
        if ($recordCompanyId === null) {
            return true;
        }

        if ($user->hasRole('owner') || $user->hasRole('system_admin')) {
            return true;
        }

        return $user->current_company_id !== null
            && $user->current_company_id === $recordCompanyId;
    }
}
