<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\Settlement;
use App\Models\User;

class SettlementPolicy
{
    /**
     * Determine whether the user can record a payment in the household.
     *
     * Call with the household: $user->can('create', [Settlement::class, $household]).
     */
    public function create(User $user, Household $household): bool
    {
        if ($household->isArchived()) {
            return false;
        }

        return $household->hasMember($user);
    }

    /**
     * Determine whether the user can confirm or reject the payment. Only the recipient can,
     * because they're the one who knows whether the money arrived.
     */
    public function respond(User $user, Settlement $settlement): bool
    {
        $household = $settlement->household;

        if ($household->isArchived()) {
            return false;
        }

        return $user->id === $settlement->to_user_id && $household->hasMember($user);
    }
}
