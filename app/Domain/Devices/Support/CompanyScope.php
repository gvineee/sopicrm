<?php

namespace App\Domain\Devices\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

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
    /**
     * Audit A14 asked for visibility to be checked with accounts of different
     * companies, and checking it exposed the gap: `allows()` answers for ONE
     * record, which is what a Policy needs, but a list endpoint builds its own
     * query and never asks. The Employees roster listed every company's people
     * to a company-scoped HR user as a result.
     *
     * This is the same rule expressed as a query constraint, so a list and the
     * detail page it links to cannot disagree about what exists. Sites and
     * Devices were not reachable that way today — only `owner`/`system_admin`
     * hold `devices.view`, and both are org-wide here — but that safety rests
     * entirely on a permission grant in a seeder, and a future grant to a
     * company-scoped role would reopen it silently. They are filtered too.
     *
     * @param  Builder<TModel>  $query
     * @param  string  $column  The record's own company column. Pass a closure
     *                          via `applyToRelation()` when the company is
     *                          inherited (a Device gets it from its Site).
     * @return Builder<TModel>
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     */
    public static function apply(Builder $query, User $user, string $column = 'company_id'): Builder
    {
        if (self::seesEveryCompany($user)) {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($user, $column): void {
            // Unassigned records stay shared: scoping tightens what an
            // assignment covers, it never hides data that was organization-wide
            // before anyone was assigned.
            $inner->whereNull($column);

            if ($user->current_company_id !== null) {
                $inner->orWhere($column, $user->current_company_id);
            }
        });
    }

    /**
     * The inherited-company variant: the record has no company column of its
     * own and takes one from a parent relation (a Device from its Site).
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     */
    public static function applyThrough(Builder $query, User $user, string $relation, string $column = 'company_id'): Builder
    {
        if (self::seesEveryCompany($user)) {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($user, $relation, $column): void {
            $inner->whereHas($relation, function (Builder $parent) use ($user, $column): void {
                $parent->whereNull($column);

                if ($user->current_company_id !== null) {
                    $parent->orWhere($column, $user->current_company_id);
                }
            });

            // A record whose parent is missing altogether has no company to be
            // scoped by, so it behaves like an unassigned one rather than
            // vanishing.
            $inner->orWhereDoesntHave($relation);
        });
    }

    public static function seesEveryCompany(User $user): bool
    {
        return $user->hasRole('owner') || $user->hasRole('system_admin');
    }

    public static function allows(?string $recordCompanyId, User $user): bool
    {
        if ($recordCompanyId === null) {
            return true;
        }

        if (self::seesEveryCompany($user)) {
            return true;
        }

        return $user->current_company_id !== null
            && $user->current_company_id === $recordCompanyId;
    }
}
