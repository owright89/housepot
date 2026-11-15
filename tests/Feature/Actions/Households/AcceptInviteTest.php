<?php

use App\Actions\Households\AcceptInvite;
use App\Enums\HouseholdRole;
use App\Exceptions\InvalidInvite;
use App\Models\Household;
use App\Models\HouseholdInvite;
use App\Models\Membership;
use App\Models\User;

test('new user joins the household as a member and uses up the invite', function () {
    $user = User::factory()->create();
    $household = Household::factory()->withOwner()->create();
    $invite = HouseholdInvite::factory()->for($household)->create();

    $joined = app(AcceptInvite::class)->handle($user, $invite);
    $invite->refresh();

    expect($joined->is($household))->toBeTrue()
        ->and($household->roleOf($user))->toBe(HouseholdRole::Member)
        ->and($invite->accepted_by)->toBe($user->id)
        ->and($invite->accepted_at)->not->toBeNull()
        ->and($invite->isUsable())->toBeFalse();
});

test('former member rejoins on their existing membership row', function () {
    $user = User::factory()->create();
    $household = Household::factory()->withOwner()->withMember($user, leftAt: now()->subMonth())->create();
    $invite = HouseholdInvite::factory()->for($household)->create();

    app(AcceptInvite::class)->handle($user, $invite);

    $membership = Membership::query()
        ->where('household_id', $household->id)
        ->where('user_id', $user->id)
        ->sole();

    expect($household->hasMember($user))->toBeTrue()
        ->and($household->roleOf($user))->toBe(HouseholdRole::Member)
        ->and($membership->left_at)->toBeNull()
        ->and($membership->joined_at->isToday())->toBeTrue()
        ->and($invite->refresh()->accepted_by)->toBe($user->id);
});

test('current member is left unchanged and the invite stays usable', function () {
    $owner = User::factory()->create();
    $household = Household::factory()->withOwner($owner)->create();
    $invite = HouseholdInvite::factory()->for($household)->create();

    app(AcceptInvite::class)->handle($owner, $invite);

    expect($household->roleOf($owner))->toBe(HouseholdRole::Owner)
        ->and($invite->refresh()->isUsable())->toBeTrue();
});

test('unusable invite is rejected and nobody joins', function (string $state) {
    $user = User::factory()->create();
    $household = Household::factory()->withOwner()->create();
    $invite = HouseholdInvite::factory()->for($household)->{$state}()->create();

    expect(fn () => app(AcceptInvite::class)->handle($user, $invite))
        ->toThrow(InvalidInvite::class);

    expect($household->hasMember($user))->toBeFalse();
})->with(['expired', 'revoked', 'accepted']);

test('invite to an archived household is rejected', function () {
    $user = User::factory()->create();
    $household = Household::factory()->archived()->withOwner()->create();
    $invite = HouseholdInvite::factory()->for($household)->create();

    expect(fn () => app(AcceptInvite::class)->handle($user, $invite))
        ->toThrow(InvalidInvite::class);

    expect($household->hasMember($user))->toBeFalse()
        ->and($invite->refresh()->isUsable())->toBeTrue();
});
