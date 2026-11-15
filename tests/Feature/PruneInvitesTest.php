<?php

use App\Models\HouseholdInvite;

use function Pest\Laravel\artisan;

test('pruning deletes unused invites that stopped working over a month ago', function () {
    $longExpired = HouseholdInvite::factory()->create(['expires_at' => now()->subDays(31)]);
    $longRevoked = HouseholdInvite::factory()->create(['revoked_at' => now()->subDays(31)]);
    $recentlyExpired = HouseholdInvite::factory()->create(['expires_at' => now()->subDays(5)]);
    $recentlyRevoked = HouseholdInvite::factory()->create(['revoked_at' => now()->subDays(5)]);
    $live = HouseholdInvite::factory()->create();
    $acceptedLongAgo = HouseholdInvite::factory()->accepted()->create(['expires_at' => now()->subDays(60)]);

    artisan('model:prune', ['--model' => [HouseholdInvite::class]])->assertSuccessful();

    expect(HouseholdInvite::find($longExpired->id))->toBeNull()
        ->and(HouseholdInvite::find($longRevoked->id))->toBeNull()
        ->and(HouseholdInvite::find($recentlyExpired->id))->not->toBeNull()
        ->and(HouseholdInvite::find($recentlyRevoked->id))->not->toBeNull()
        ->and(HouseholdInvite::find($live->id))->not->toBeNull()
        ->and(HouseholdInvite::find($acceptedLongAgo->id))->not->toBeNull();
});

test('pruning is scheduled daily', function () {
    artisan('schedule:list')->expectsOutputToContain('model:prune')->assertSuccessful();
});
