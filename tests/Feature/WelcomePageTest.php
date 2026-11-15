<?php

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

test('guests see the landing page with sign-up links', function () {
    get(route('home'))
        ->assertOk()
        ->assertSee('Split the bills,')
        ->assertSee('Sorted in three steps')
        ->assertSee(route('register'))
        ->assertSee(route('login'));
});

test('signed-in users are offered the dashboard instead of logging in', function () {
    actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertOk()
        ->assertSee(route('dashboard'))
        ->assertSee(route('households.index'))
        ->assertDontSee('Log in');
});

test('the landing page is always light, ignoring the dark mode preference', function () {
    get(route('home'))->assertDontSee('flux.appearance', false);
});
