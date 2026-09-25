<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Models\Membership;
use App\Domain\Auth\Models\Organization;
use App\Domain\Auth\Support\OrganizationDatabaseContext;
use App\Domain\Companies\Models\Company;
use App\Domain\Companies\Models\CompanyMembership;
use App\Domain\Shared\Services\AuditLogger;
use App\Domain\Shared\Services\CurrentCompany;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates a new tenant organization together with its first owner account,
 * set up the same way Database\Seeders\DatabaseSeeder sets up an
 * organization: a default company (code DEFAULT, as the companies migration
 * backfilled for every existing organization), the owner as its primary
 * member, and the global `owner` role assigned with the spatie team id set to
 * the NEW organization. Roles are global templates (RbacBaseSeeder), so
 * nothing per-organization has to be seeded for the owner's permissions to
 * resolve — only the team-scoped assignment.
 *
 * The audit record is written under the acting platform admin's own
 * organization, so deleting the new organization later cannot erase the
 * record of who created it.
 */
class CreateOrganizationAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{name: string, legal_name: string|null, default_currency: string, default_timezone: string}  $organizationData
     * @param  array{name: string, email: string, password: string}  $ownerData
     */
    public function execute(array $organizationData, array $ownerData, User $actor): Organization
    {
        if (! $actor->is_platform_admin) {
            throw new AuthorizationException('Only a platform admin may create an organization.');
        }

        $auditOrganizationId = CurrentOrganization::id() ?? $actor->organization_id;

        return DB::transaction(function () use ($organizationData, $ownerData, $actor, $auditOrganizationId): Organization {
            $organization = Organization::query()->create([
                ...$organizationData,
                'is_active' => true,
            ]);

            $owner = $this->withinOrganization($organization, function () use ($organization, $organizationData, $ownerData): User {
                $company = new Company;
                $company->forceFill([
                    'organization_id' => $organization->id,
                    'name' => $organization->name,
                    'legal_name' => $organizationData['legal_name'],
                    'code' => 'DEFAULT',
                    'default_currency' => $organizationData['default_currency'],
                    'default_timezone' => $organizationData['default_timezone'],
                    'is_active' => true,
                ])->save();

                $owner = new User;
                $owner->forceFill([
                    'organization_id' => $organization->id,
                    'current_organization_id' => $organization->id,
                    'current_company_id' => $company->id,
                    'name' => $ownerData['name'],
                    'email' => $ownerData['email'],
                    'password' => $ownerData['password'],
                    'is_active' => true,
                    'email_verified_at' => now(),
                ])->save();

                CompanyMembership::query()->create([
                    'organization_id' => $organization->id,
                    'company_id' => $company->id,
                    'user_id' => $owner->id,
                    'is_primary' => true,
                ]);

                Membership::query()->create([
                    'organization_id' => $organization->id,
                    'user_id' => $owner->id,
                    'is_primary' => true,
                ]);

                $owner->assignRole('owner');

                return $owner;
            });

            $this->auditLogger->log(
                action: 'platform.organization.created',
                target: $organization,
                after: [
                    ...$organization->only(['id', 'name', 'legal_name', 'default_currency', 'default_timezone']),
                    'owner_user_id' => $owner->id,
                    'owner_email' => $owner->email,
                ],
                actor: $actor,
                organizationId: $auditOrganizationId,
            );

            return $organization;
        });
    }

    /**
     * Points every tenancy layer (Eloquent scope, spatie team id, Postgres
     * RLS) at the new organization for the callback, then restores the
     * acting admin's own context.
     *
     * @template TResult
     *
     * @param  \Closure(): TResult  $callback
     * @return TResult
     */
    private function withinOrganization(Organization $organization, \Closure $callback): mixed
    {
        $registrar = app(PermissionRegistrar::class);
        $previousOrganization = CurrentOrganization::id();
        $previousCompany = CurrentCompany::id();
        $previousTeam = $registrar->getPermissionsTeamId();

        CurrentOrganization::set($organization->id);
        CurrentCompany::set(null);
        $registrar->setPermissionsTeamId($organization->id);

        try {
            return OrganizationDatabaseContext::run($organization->id, $callback);
        } finally {
            CurrentOrganization::set($previousOrganization);
            CurrentCompany::set($previousCompany);
            $registrar->setPermissionsTeamId($previousTeam);
        }
    }
}
