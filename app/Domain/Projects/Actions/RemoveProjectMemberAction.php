<?php

namespace App\Domain\Projects\Actions;

use App\Domain\Auth\Models\ProjectMembership;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Soft-revokes a membership (`removed_at`), never a hard delete — spec/
 * data-model.md explicit: history is kept intentionally so past access can
 * be audited later.
 */
class RemoveProjectMemberAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(ProjectMembership $membership, User $actor): ProjectMembership
    {
        return DB::transaction(function () use ($membership, $actor): ProjectMembership {
            if ($membership->removed_at !== null) {
                throw ValidationException::withMessages([
                    'user_id' => ['ეს წევრობა უკვე გაუქმებულია.'],
                ]);
            }

            if ($membership->project->manager_user_id === $membership->user_id) {
                throw ValidationException::withMessages([
                    'user_id' => ['პროექტის მენეჯერის წევრობის მოხსნამდე ჯერ სხვა მენეჯერი დანიშნეთ.'],
                ]);
            }

            $removedAt = now();
            $membership->update(['removed_at' => $removedAt]);

            $this->auditLogger->log(
                action: 'projects.membership.removed',
                target: $membership,
                before: ['removed_at' => null],
                after: ['removed_at' => $removedAt->toIso8601String()],
                actor: $actor,
            );

            return $membership;
        });
    }
}
