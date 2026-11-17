<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\Settlement;
use App\Models\User;

class SettlementPolicy
{
    /**
     * Determine whether the user can create a settlement.
     */
    public function create(User $user, Household $household): bool
    {
        if ($household->isArchived()) {
            return false;
        }

        return $household->hasMember($user);
    }
}
