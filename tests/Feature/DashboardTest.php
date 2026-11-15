<?php

use App\Models\Household;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard prompts users without a household to set one up', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Set up your household');
});

test('dashboard shows the current household', function () {
    $user = User::factory()->create(['name' => 'Ollie Wright']);
    Household::factory()->withOwner($user)->create(['name' => 'Elm Street']);
    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Ollie')
        ->assertSee('Here&#039;s where Elm Street stands this month.', false)
        ->assertSee('Upcoming bills');
});
