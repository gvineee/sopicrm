<?php

namespace App\Domain\Auth\Support;

use App\Domain\Auth\Models\Organization;
use App\Models\User;

/**
 * See App\Http\Middleware\SetCurrentOrganization::organizationIdForPrincipal()'s
 * docblock for why this takes `?object` rather than branching directly on
 * `$request->user()`'s inferred type inline. Used by
 * routes/modules/api-auth.php's `/api/v1/me` endpoint.
 */
class ApiPrincipal
{
    /**
     * @return array<string, mixed>
     */
    public static function describe(?object $principal): array
    {
        if ($principal instanceof User) {
            return [
                'type' => 'user',
                'id' => $principal->getKey(),
                'organizationId' => $principal->current_organization_id,
            ];
        }

        if ($principal instanceof Organization) {
            return [
                'type' => 'machine',
                'organizationId' => $principal->getKey(),
            ];
        }

        abort(401);
    }
}
