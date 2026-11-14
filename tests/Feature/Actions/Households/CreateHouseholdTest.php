<?php

use App\Actions\Households\CreateHousehold;
use App\Enums\HouseholdRole;
use App\Models\User;

test('creates a household with the given name and currency, owned by the creator', function () {
    $owner = User::factory()->create();
    $name = 'Test Household';
    $currency = 'USD';

    $household = app(CreateHousehold::class)->handle($owner, $name, $currency);

    expect($household->name)->toBe($name)
        ->and($household->currency)->toBe($currency)
        ->and($household->hasMember($owner))->toBeTrue()
        ->and($household->roleOf($owner))->toBe(HouseholdRole::Owner);
});
