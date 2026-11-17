<?php

use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Live updates for a household. Same rule as viewing it: current members only (HouseholdPolicy::view).
Broadcast::channel('households.{household}', function (User $user, Household $household): bool {
    return $user->can('view', $household);
});

// Who's on the household page right now (presence). Same rule as the private channel; the
// returned array is what other members see about you, so keep it to what the UI needs.
Broadcast::channel('household.{household}', function (User $user, Household $household): array|false {
    if (! $user->can('view', $household)) {
        return false;
    }

    return ['id' => $user->id, 'name' => Str::before($user->name, ' ')];
});
