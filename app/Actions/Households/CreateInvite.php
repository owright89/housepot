<?php

namespace App\Actions\Households;

use App\Models\Household;
use App\Models\HouseholdInvite;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateInvite
{
    /**
     * How long an invite link stays usable.
     */
    public const EXPIRES_AFTER_DAYS = 7;

    /**
     * Create a new single-use invite and revoke any other live link, so a household
     * only ever has one invite that works.
     *
     * Authorisation (HouseholdPolicy::invite) is the caller's job.
     */
    public function handle(User $inviter, Household $household): HouseholdInvite
    {
        return DB::transaction(function () use ($inviter, $household): HouseholdInvite {
            // Lock the household so two clicks at once can't both leave a live link.
            Household::query()->lockForUpdate()->findOrFail($household->id);

            $household->invites()->usable()->update(['revoked_at' => now()]);

            return $household->invites()->create([
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(self::EXPIRES_AFTER_DAYS),
            ]);
        });
    }
}
