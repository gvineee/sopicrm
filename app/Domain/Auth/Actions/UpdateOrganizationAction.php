<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Models\Organization;
use App\Domain\Shared\Services\AuditLogger;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Renames an organization (name / legal name). Audited under the acting
 * platform admin's own organization, like the other organization actions.
 */
class UpdateOrganizationAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{name: string, legal_name: string|null}  $data
     */
    public function execute(Organization $organization, array $data, User $actor): Organization
    {
        if (! $actor->is_platform_admin) {
            throw new AuthorizationException('Only a platform admin may edit an organization.');
        }

        $auditOrganizationId = CurrentOrganization::id() ?? $actor->organization_id;

        return DB::transaction(function () use ($organization, $data, $actor, $auditOrganizationId): Organization {
            $organization->fill($data);

            if (! $organization->isDirty()) {
                return $organization;
            }

            $after = $organization->getDirty();
            $before = array_intersect_key($organization->getOriginal(), $after);

            $organization->save();

            $this->auditLogger->log(
                action: 'platform.organization.updated',
                target: $organization,
                before: $before,
                after: $after,
                actor: $actor,
                organizationId: $auditOrganizationId,
            );

            return $organization;
        });
    }
}
