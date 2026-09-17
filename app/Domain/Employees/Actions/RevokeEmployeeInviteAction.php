<?php

namespace App\Domain\Employees\Actions;

use App\Domain\Employees\Models\EmployeeInvite;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;

class RevokeEmployeeInviteAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(EmployeeInvite $invite, User $actor): EmployeeInvite
    {
        if ($invite->status !== 'pending') {
            return $invite;
        }

        $invite->status = 'revoked';
        $invite->revoked_at = now();
        $invite->revoked_by_user_id = $actor->id;
        $invite->save();

        $this->auditLogger->log(
            action: 'employees.invite.revoked',
            target: $invite,
            actor: $actor,
        );

        return $invite;
    }
}
