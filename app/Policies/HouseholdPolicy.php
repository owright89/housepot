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
        if ($household->isArchived()) {
            return false;
        }

        return $household->roleOf($user) === HouseholdRole::Owner;
    }
}
