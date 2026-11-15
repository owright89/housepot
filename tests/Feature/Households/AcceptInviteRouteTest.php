<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdInvite;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

test('guests are sent to log in first', function () {
    $invite = HouseholdInvite::factory()->create();

    get($invite->url())->assertRedirect(route('login'));
});

test('signed-in user joins the household from the link', function () {
    $user = User::factory()->create();
    $household = Household::factory()->withOwner()->create(['name' => 'Elm Street']);
    $invite = HouseholdInvite::factory()->for($household)->create();

    actingAs($user)->get($invite->url())
        ->assertRedirect(route('households.show', $household))
        ->assertSessionHas('invite_status', 'Welcome to Elm Street!');

    expect($household->roleOf($user))->toBe(HouseholdRole::Member)
        ->and($user->currentHousehold()->is($household))->toBeTrue();

    actingAs($user)->get(route('households.show', $household))->assertSee('Welcome to Elm Street!');
});

test('a link without a valid signature is rejected', function () {
    $user = User::factory()->create();
    $invite = HouseholdInvite::factory()->create();

    actingAs($user)->get(route('invites.accept', $invite))->assertForbidden();
    actingAs($user)->get($invite->url().'0')->assertForbidden();

    expect($invite->household->hasMember($user))->toBeFalse();
});

test('an unusable invite shows a friendly error', function (string $state) {
    $user = User::factory()->create();
    $invite = HouseholdInvite::factory()->{$state}()->create();

    actingAs($user)->get($invite->url())
        ->assertRedirect(route('households.index'))
        ->assertSessionHas('invite_error');

    expect($invite->household->hasMember($user))->toBeFalse();

    actingAs($user)->get(route('households.index'))->assertSee('Couldn&#039;t join that household', false);
})->with(['expired', 'revoked', 'accepted']);

test('an existing member is taken to the household and the invite is not used up', function () {
    $owner = User::factory()->create();
    $household = Household::factory()->withOwner($owner)->create();
    $invite = HouseholdInvite::factory()->for($household)->create();

    actingAs($owner)->get($invite->url())->assertRedirect(route('households.show', $household));

    expect($invite->refresh()->isUsable())->toBeTrue();
});
