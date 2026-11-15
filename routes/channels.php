<?php

use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Live updates for a household. Same rule as viewing it: current members only (HouseholdPolicy::view).
Broadcast::channel('households.{household}', function (User $user, Household $household): bool {
    return $user->can('view', $household);
});
