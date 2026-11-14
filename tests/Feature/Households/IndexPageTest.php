<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('households.index'))->assertRedirect(route('login'));
});

test('households page is displayed', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('households.index'))->assertOk();
});

test('empty state is shown when the user has no households', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::households.index')
        ->assertSee('No households yet')
        ->assertSee('Create a household');
});

test('only current households of the user are listed', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Household::factory()->withOwner($user)->create(['name' => 'Elm Street']);
    Household::factory()->withOwner()->withMember($user, leftAt: now())->create(['name' => 'Old Flat']);
    Household::factory()->withOwner()->create(['name' => 'Strangers House']);

    Livewire::test('pages::households.index')
        ->assertSee('Elm Street')
        ->assertDontSee('Old Flat')
        ->assertDontSee('Strangers House')
        ->assertDontSee('No households yet');
});

test('listed households show the current member count and the user role', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Household::factory()
        ->withOwner()
        ->withMember($user)
        ->withMember()
        ->withMember(leftAt: now())
        ->create(['name' => 'Elm Street']);

    Livewire::test('pages::households.index')
        ->assertSee('3 members')
        ->assertSee('Member')
        ->assertDontSee('Owner');
});

test('user can create a household and becomes its owner', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::households.index')
        ->set('name', 'Elm Street')
        ->set('currency', 'EUR')
        ->call('create')
        ->assertHasNoErrors()
        ->assertSet('name', '')
        ->assertSee('Elm Street');

    $household = Household::where('name', 'Elm Street')->sole();

    expect($household->currency)->toBe('EUR')
        ->and($household->roleOf($user))->toBe(HouseholdRole::Owner);
});

test('household creation is validated', function (array $input, string $field) {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::households.index')
        ->set($input)
        ->call('create')
        ->assertHasErrors($field);

    expect(Household::count())->toBe(0);
})->with([
    'blank name' => [['name' => '', 'currency' => 'GBP'], 'name'],
    'name too long' => [['name' => str_repeat('a', 61), 'currency' => 'GBP'], 'name'],
    'unsupported currency' => [['name' => 'Elm Street', 'currency' => 'JPY'], 'currency'],
]);
