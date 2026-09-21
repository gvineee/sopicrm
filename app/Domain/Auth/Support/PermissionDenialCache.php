<?php

namespace App\Domain\Auth\Support;

use App\Domain\Auth\Models\UserPermissionDenial;

/**
 * ADMIN-02: per-user, per-organization denial-name memoization for the
 * deny-override Gate::before check (App\Providers\AppServiceProvider::boot()).
 * A plain in-process array, not a Laravel cache store: it must never survive
 * past the current unit of work, since a queue worker reuses one PHP
 * process across many different users' jobs and a stale entry would leak
 * one user's denials onto another (or, within a single request/test, onto
 * a later check for the same user after a denial was just added/removed).
 * `forget()` is called by DenyUserPermissionAction and
 * RemoveUserPermissionDenialAction immediately after they change a row, so
 * a later `can()` check in the same request/process always sees the
 * current state rather than a check made earlier in the same request.
 */
class PermissionDenialCache
{
    /**
     * @var array<string, list<string>>
     */
    private static array $namesByUserOrg = [];

    /**
     * @return list<string>
     */
    public static function deniedPermissionNames(string $organizationId, string $userId): array
    {
        $key = self::key($organizationId, $userId);

        return self::$namesByUserOrg[$key] ??= array_values(UserPermissionDenial::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->pluck('permission_name')
            ->map(fn (mixed $name): string => (string) $name)
            ->all());
    }

    public static function forget(string $organizationId, string $userId): void
    {
        unset(self::$namesByUserOrg[self::key($organizationId, $userId)]);
    }

    private static function key(string $organizationId, string $userId): string
    {
        return "{$organizationId}:{$userId}";
    }
}
