<?php

namespace App\Domain\Employees\Actions;

use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\Team;
use App\Domain\Employees\Models\TeamMembership;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * docs/data-model.md "team_memberships": an employee is on at most one team
 * at a time (partial unique index on (organization_id, employee_id) WHERE
 * ended_at IS NULL — a documented v1 simplification). Moving an employee to
 * a new team therefore always ends any current active membership first,
 * inside the same transaction, and updates the denormalized
 * `employees.team_id` pointer used for quick display/filtering.
 */
class SetTeamMembershipAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(Employee $employee, ?Team $team, string $startedOn, User $actor): ?TeamMembership
    {
        return DB::transaction(function () use ($employee, $team, $startedOn, $actor) {
            TeamMembership::query()
                ->where('employee_id', $employee->id)
                ->whereNull('ended_at')
                ->update(['ended_at' => now()->toDateString()]);

            $employee->team_id = $team?->id;
            $employee->save();

            if ($team === null) {
                $this->auditLogger->log(
                    action: 'employees.team_membership.ended',
                    target: $employee,
                    actor: $actor,
                );

                return null;
            }

            $membership = TeamMembership::query()->create([
                'team_id' => $team->id,
                'employee_id' => $employee->id,
                'started_at' => $startedOn,
            ]);

            $this->auditLogger->log(
                action: 'employees.team_membership.assigned',
                target: $membership,
                after: ['team_id' => $team->id, 'employee_id' => $employee->id, 'started_at' => $startedOn],
                actor: $actor,
            );

            return $membership;
        });
    }
}
