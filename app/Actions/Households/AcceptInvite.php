<?php

namespace App\Actions\Households;

use App\Enums\HouseholdRole;
use App\Exceptions\InvalidInvite;
use App\Models\Household;
use App\Models\HouseholdInvite;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AcceptInvite
{
    /**
     * Add the user to the invite's household and use up the invite.
     *
     * A current member is returned unchanged and the invite stays usable.
     * A former member rejoins on their existing membership row.
     *
     * @throws InvalidInvite when the invite is expired, revoked or used, or the household is archived.
     */
    public function handle(User $user, HouseholdInvite $invite): Household
    {
        return DB::transaction(function () use ($user, $invite): Household {
            // Re-fetch with a row lock so two people can't use the same invite at once.
            $invite = HouseholdInvite::query()->lockForUpdate()->findOrFail($invite->id);

            if (! $invite->isUsable()) {
                throw new InvalidInvite('This invite is no longer valid.');
            }

            $household = $invite->household;

            if ($household->isArchived()) {
                throw new InvalidInvite('This household is archived and can\'t be joined.');
            }

            if ($household->hasMember($user)) {
                return $household;
            }

            if ($household->allMembers()->whereKey($user->id)->exists()) {
                $household->allMembers()->updateExistingPivot($user->id, [
                    'role' => HouseholdRole::Member,
                    'joined_at' => now(),
                    'left_at' => null,
                ]);
            } else {
                $household->allMembers()->attach($user, ['role' => HouseholdRole::Member]);
            }

            $invite->forceFill([
                'accepted_at' => now(),
                'accepted_by' => $user->id,
            ])->save();

            return $household;
        });
    }
}
