<?php

namespace App\Policies;

use App\Domain\Contractors\Models\ContractorAct;
use App\Domain\Projects\Models\Project;
use App\Models\User;

/**
 * Acts always belong to a project, so — mirroring
 * App\Policies\TaskPolicy::hasProjectAccess — a permission alone isn't
 * enough; the user must also be an active member of that project (or
 * `owner`, which bypasses membership everywhere else in this codebase).
 */
class ContractorActPolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $this->hasProjectAccess($user, $project, 'contractors.contractors.view');
    }

    public function view(User $user, ContractorAct $act): bool
    {
        return $this->hasProjectAccess($user, $act->project, 'contractors.contractors.view');
    }

    public function submit(User $user, Project $project): bool
    {
        return $this->hasProjectAccess($user, $project, 'contractors.acts.submit');
    }

    public function accept(User $user, ContractorAct $act): bool
    {
        return $this->hasProjectAccess($user, $act->project, 'contractors.acts.review');
    }

    public function returnAct(User $user, ContractorAct $act): bool
    {
        return $this->hasProjectAccess($user, $act->project, 'contractors.acts.review');
    }

    private function hasProjectAccess(User $user, Project $project, string $permission): bool
    {
        if ($project->organization_id !== $user->organization_id) {
            return false;
        }

        if (! $user->can($permission)) {
            return false;
        }

        if ($user->hasRole('owner')) {
            return true;
        }

        return $user->isActiveMemberOfProject($project->id);
    }
}
