<?php

use App\Models\Household;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

test('an unknown URL shows the moved-out page', function () {
    get('/this-page-does-not-exist')
        ->assertNotFound()
        ->assertSee('This page has moved out')
        ->assertSee(route('dashboard'))
        ->assertSee(route('home'));
});

test('a missing household shows it too', function () {
    actingAs(User::factory()->create())
        ->get(route('households.show', 999999))
        ->assertNotFound()
        ->assertSee('This page has moved out');
});

test('it never reveals another household, which is a 403 not a 404', function () {
    $household = Household::factory()->withOwner()->create();

    actingAs(User::factory()->create())
        ->get(route('households.show', $household))
        ->assertForbidden();
});
