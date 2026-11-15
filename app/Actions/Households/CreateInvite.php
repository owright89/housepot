<?php

namespace App\Actions\Households;

use App\Models\Household;
use App\Models\HouseholdInvite;
use App\Models\User;

class CreateInvite
{
    /**
     * How long an invite link stays usable.
     */
    public const EXPIRES_AFTER_DAYS = 7;

    /**
     * Create a single-use invite to the household.
     *
     * Authorisation (HouseholdPolicy::invite) is the caller's job.
     */
    public function handle(User $inviter, Household $household): HouseholdInvite
    {
        return $household->invites()->create([
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(self::EXPIRES_AFTER_DAYS),
        ]);
    }
}
