<?php

namespace App\Policies;

use App\Domain\Employees\Models\Position;
use App\Models\User;

/**
 * Positions are an Employees sub-resource — gated by the same
 * `employees.employees.*` permissions as the employee roster itself rather
 * than a new permission pair, since managing the list of selectable
 * positions is part of the same HR/owner responsibility.
 */
class PositionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('employees.employees.view');
    }

    public function create(User $user): bool
    {
        return $user->can('employees.employees.manage');
    }

    public function update(User $user, Position $position): bool
    {
        return $position->organization_id === $user->organization_id
            && $user->can('employees.employees.manage');
    }
}
