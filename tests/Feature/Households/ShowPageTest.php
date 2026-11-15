<?php

use App\Models\Household;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

test('guests are redirected to the login page', function () {
    $household = Household::factory()->withOwner()->create();

    get(route('households.show', $household))->assertRedirect(route('login'));
});

test('current member sees the page', function () {
    $user = User::factory()->create();
    $household = Household::factory()
        ->withOwner()
        ->withMember($user)
        ->create();

    actingAs($user)->get(route('households.show', $household))
        ->assertOk()
        ->assertSee($household->name);
});

test('former member is forbidden', function () {
    $user = User::factory()->create();
    $household = Household::factory()
        ->withOwner()
        ->withMember($user, leftAt: now())
        ->create();

    actingAs($user)->get(route('households.show', $household))
        ->assertForbidden();
});

test('stranger is forbidden', function () {
    $user = User::factory()->create();
    $household = Household::factory()
        ->withOwner()
        ->withMember()
        ->create();

    actingAs($user)->get(route('households.show', $household))
        ->assertForbidden();
});

test('only current members are listed', function () {
    $user = User::factory()->create(['name' => 'Dave']);
    $household = Household::factory()
        ->withOwner($user)
        ->withMember(User::factory()->create(['name' => 'Bob']))
        ->withMember(User::factory()->create(['name' => 'Carl']), leftAt: now())
        ->create();

    actingAs($user)->get(route('households.show', $household))
        ->assertSee('Dave')
        ->assertSee('Bob')
        ->assertDontSee('Carl');
});

test('an archived household shows the callout', function () {
    $user = User::factory()->create();
    $household = Household::factory()
        ->archived()
        ->withMember($user)
        ->create();

    actingAs($user)->get(route('households.show', $household))
        ->assertSee('This household is archived');
});

test('only the owner sees the invite buttons', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $household = Household::factory()->withOwner($owner)->withMember($member)->create();

    actingAs($owner)->get(route('households.show', $household))->assertSee('Invite housemate');
    actingAs($member)->get(route('households.show', $household))->assertDontSee('Invite housemate');
});

test('bills can be filtered to paid only', function () {
    $user = User::factory()->create();
    $household = Household::factory()->withOwner($user)->create();
    actingAs($user);

    Livewire\Livewire::test('pages::households.show', ['household' => $household])
        ->assertSee('Broadband')
        ->set('filter', 'paid')
        ->assertSee('Water')
        ->assertDontSee('Broadband');
});
