<?php

namespace App\Domain\Employees\Actions;

use App\Domain\Employees\Models\Team;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;

class CreateTeamAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{name: string, foreman_employee_id?: string|null}  $data
     */
    public function execute(array $data, User $actor): Team
    {
        $team = Team::query()->create([
            'name' => $data['name'],
            'foreman_employee_id' => $data['foreman_employee_id'] ?? null,
            'is_active' => true,
        ]);

        $this->auditLogger->log(
            action: 'employees.team.created',
            target: $team,
            after: $team->only(['name', 'foreman_employee_id']),
            actor: $actor,
        );

        return $team;
    }
}
