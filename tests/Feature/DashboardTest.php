<?php

use App\Actions\Expenses\ExpenseData;
use App\Actions\Expenses\RecordExpense;
use App\Ledger\SplitCalculator;
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
        ->assertSee('Recent bills');
});

test('dashboard shows real balances, bills and activity', function () {
    $alice = User::factory()->create(['name' => 'Alice Smith']);
    $bob = User::factory()->create(['name' => 'Bob Jones']);
    $household = Household::factory()->withOwner($alice)->withMember($bob)->create();

    app(RecordExpense::class)->handle($bob, $household, new ExpenseData(
        $bob, 'Energy', 9640, now(), SplitCalculator::equal(9640, [$alice->id, $bob->id]),
    ));

    $this->actingAs($alice)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeHtml('data-test="you-owe">£48.20')
        ->assertSee('To Bob')
        ->assertSeeHtml('data-test="owed-to-you">£0.00')
        ->assertSee('Energy')
        ->assertSee('Bob added Energy');
});
