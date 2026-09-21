<?php

namespace App\Policies;

use App\Domain\Assets\Models\Stocktake;
use App\Models\User;

/**
 * Stocktake (ASSETS-01 deferred remainder): two distinct permissions, not
 * one — `assets.stocktakes.perform` (start a session, record counts) is a
 * separation-of-duties boundary from `assets.stocktakes.approve` (resolve a
 * variance, close the session). A counter without approve permission can
 * never resolve their own variance; an approver without perform permission
 * can never record a count themselves — the two abilities are independently
 * enforced, not a single combined "manage" grant.
 */
class StocktakePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('assets.stocktakes.perform') || $user->can('assets.stocktakes.approve');
    }

    public function view(User $user, Stocktake $stocktake): bool
    {
        return $stocktake->organization_id === $user->organization_id && $this->viewAny($user);
    }

    public function perform(User $user): bool
    {
        return $user->can('assets.stocktakes.perform');
    }

    public function count(User $user, Stocktake $stocktake): bool
    {
        return $stocktake->organization_id === $user->organization_id
            && $user->can('assets.stocktakes.perform');
    }

    public function approve(User $user, Stocktake $stocktake): bool
    {
        return $stocktake->organization_id === $user->organization_id
            && $user->can('assets.stocktakes.approve');
    }

    public function complete(User $user, Stocktake $stocktake): bool
    {
        return $stocktake->organization_id === $user->organization_id
            && $user->can('assets.stocktakes.approve');
    }
}
