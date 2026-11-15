<?php

namespace App\Policies;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;

class HouseholdPolicy
{
    /**
     * Determine whether the user can view the household.
     */
    public function view(User $user, Household $household): bool
    {
        return $household->hasMember($user);
    }

    /**
     * Determine whether the user can update the household.
     */
    public function update(User $user, Household $household): bool
    {
        return $this->isActiveOwner($user, $household);
    }

    /**
     * Determine whether the user can invite another user to a household.
     */
    public function invite(User $user, Household $household): bool
    {
        return $this->isActiveOwner($user, $household);
    }

    /**
     * Whether the user owns the household and it isn't archived.
     */
    private function isActiveOwner(User $user, Household $household): bool
    {
        if ($household->isArchived()) {
            return false;
        }

        return $household->roleOf($user) === HouseholdRole::Owner;
    }
}
