<?php

namespace App\Policies;

use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\Team;
use App\Models\User;

/**
 * Team/brigade management (spec section 5's "ბრიგადა" field + spec section
 * 3's foreman row: "თავისი ბრიგადის ამოცანები... მხოლოდ მისთვის მინიჭებული
 * გუნდი"). Creating/renaming teams and assigning the foreman is an HR/owner
 * action; a foreman may view (never edit) their own team.
 */
class TeamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('employees.teams.manage') || $user->hasRole('foreman');
    }

    public function view(User $user, Team $team): bool
    {
        if ($team->organization_id !== $user->organization_id) {
            return false;
        }

        if ($user->can('employees.teams.manage')) {
            return true;
        }

        return Employee::query()
            ->where('id', $team->foreman_employee_id)
            ->where('user_id', $user->id)
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('employees.teams.manage');
    }

    public function update(User $user, Team $team): bool
    {
        return $team->organization_id === $user->organization_id
            && $user->can('employees.teams.manage');
    }
}
