<?php

namespace App\Policies;

use App\Domain\Projects\Models\Client;
use App\Models\User;

/**
 * `clients` is org-scoped reference data with no project-membership
 * dimension (a client isn't "a member of a project," a project points at
 * one) — so this Policy is a plain permission + tenant check, unlike
 * ProjectPolicy's membership-joint checks.
 */
class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('projects.view') || $user->can('projects.clients.manage');
    }

    public function view(User $user, Client $client): bool
    {
        return $client->organization_id === $user->organization_id && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('projects.clients.manage');
    }

    public function update(User $user, Client $client): bool
    {
        return $client->organization_id === $user->organization_id && $user->can('projects.clients.manage');
    }
}
