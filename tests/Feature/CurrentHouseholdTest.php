<?php

use App\Models\Household;
use App\Models\User;
use App\Support\Money;

use function Pest\Laravel\actingAs;

test('current household defaults to the first by name', function () {
    $user = User::factory()->create();
    Household::factory()->withOwner($user)->create(['name' => 'Zebra Road']);
    $first = Household::factory()->withOwner($user)->create(['name' => 'Apple Street']);

    expect($user->currentHousehold()->is($first))->toBeTrue();
});

test('opening a household makes it the current household', function () {
    $user = User::factory()->create();
    Household::factory()->withOwner($user)->create(['name' => 'Apple Street']);
    $zebra = Household::factory()->withOwner($user)->create(['name' => 'Zebra Road']);

    actingAs($user)->get(route('households.show', $zebra))->assertOk();

    expect($user->currentHousehold()->is($zebra))->toBeTrue();
});

test('a remembered household the user has left is ignored', function () {
    $user = User::factory()->create();
    $current = Household::factory()->withOwner($user)->create(['name' => 'Apple Street']);
    $left = Household::factory()->withOwner()->withMember($user, leftAt: now())->create(['name' => 'Old Flat']);

    session(['current_household_id' => $left->id]);

    expect($user->currentHousehold()->is($current))->toBeTrue();
});

test('user without households has no current household', function () {
    expect(User::factory()->create()->currentHousehold())->toBeNull();
});

test('money is formatted from pence', function () {
    expect(Money::format(11820))->toBe('£118.20')
        ->and(Money::format(165000, 'EUR'))->toBe('€1,650.00')
        ->and(Money::signed(7570))->toBe('+£75.70')
        ->and(Money::signed(-4250))->toBe('-£42.50')
        ->and(Money::signed(0))->toBe('£0.00');
});
