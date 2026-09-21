<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * ADMIN-01 (docs/claude-platform-completion-2026-09-21.md): the only
 * legitimate way to set `users.is_platform_admin` — the column is
 * deliberately excluded from `User::$fillable` so no ordinary
 * profile/user-update form can touch it. An ordinary user (even one editing
 * their own account) can never grant themselves this via mass assignment;
 * only an existing platform admin can invoke this Action at all, and every
 * grant is audited.
 */
class GrantPlatformAdminAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(User $target, User $actor, string $reason): User
    {
        if (! $actor->is_platform_admin) {
            throw new AuthorizationException('Only an existing platform admin may grant platform-admin access.');
        }

        $before = $target->only(['is_platform_admin']);

        $target->is_platform_admin = true;
        $target->save();

        $this->auditLogger->log(
            action: 'auth.platform_admin.granted',
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
