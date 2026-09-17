<?php

namespace App\Domain\Shared\Services;

use RuntimeException;

/**
 * Request/job-scoped holder for "which organization is this unit of work
 * running as." Set exactly once per request by
 * App\Http\Middleware\SetCurrentOrganization (web/session guard) or per job
 * by a queue-job base class re-deriving it from stored job context — never
 * written from anywhere else, and NEVER from client-supplied input (hard
 * constraint: tenant id is derived server-side only).
 *
 * App\Domain\Shared\Concerns\BelongsToOrganization reads this (not
 * Auth::user() directly) so that background jobs / console commands that
 * have no authenticated user can still stamp/scope queries correctly by
 * setting this explicitly for their own run.
 */
final class CurrentOrganization
{
    private static ?string $organizationId = null;

    public static function set(?string $organizationId): void
    {
        self::$organizationId = $organizationId;
    }

    public static function id(): ?string
    {
        return self::$organizationId;
    }

    public static function requireId(): string
    {
        if (self::$organizationId === null) {
            throw new RuntimeException(
                'No current organization is set. A tenant-scoped write was attempted outside '.
                'any request/job context that established one via CurrentOrganization::set().'
            );
        }

        return self::$organizationId;
    }

    public static function clear(): void
    {
        self::$organizationId = null;
    }
}
