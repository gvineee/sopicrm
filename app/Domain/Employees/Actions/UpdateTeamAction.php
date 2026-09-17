<?php

namespace App\Domain\Employees\Actions;

use App\Domain\Employees\Models\Team;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;

class UpdateTeamAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{name?: string, foreman_employee_id?: string|null, is_active?: bool}  $data
     */
    public function execute(Team $team, array $data, User $actor): Team
    {
        $allowed = array_intersect_key($data, array_flip(['name', 'foreman_employee_id', 'is_active']));
        $before = $team->only(array_keys($allowed));

        $team->fill($allowed);
        $team->save();

        $this->auditLogger->log(
            action: 'employees.team.updated',
            target: $team,
            before: $before,
            after: $team->only(array_keys($allowed)),
            actor: $actor,
        );

        return $team;
    }
}
