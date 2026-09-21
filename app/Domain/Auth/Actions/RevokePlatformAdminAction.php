<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

/**
 * Symmetric with GrantPlatformAdminAction. Refuses to revoke the last
 * remaining platform admin — an accidental or malicious revoke must never be
 * able to lock every account out of platform administration entirely.
 */
class RevokePlatformAdminAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(User $target, User $actor, string $reason): User
    {
        if (! $actor->is_platform_admin) {
            throw new AuthorizationException('Only an existing platform admin may revoke platform-admin access.');
        }

        if ($target->is_platform_admin && User::query()->where('is_platform_admin', true)->count() <= 1) {
            throw new RuntimeException('Cannot revoke the last remaining platform admin.');
        }

        $before = $target->only(['is_platform_admin']);

        $target->is_platform_admin = false;
        $target->save();

        $this->auditLogger->log(
            action: 'auth.platform_admin.revoked',
            target: $target,
            before: $before,
            after: $target->only(['is_platform_admin']),
            reason: $reason,
            actor: $actor,
            organizationId: $target->organization_id,
        );

        return $target->fresh();
    }
}
