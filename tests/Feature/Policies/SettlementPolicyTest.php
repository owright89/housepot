<?php

use App\Models\Household;
use App\Models\Settlement;
use App\Models\User;

test('member can create a settlement in their household', function () {
    $member = User::factory()->create();
    $household = Household::factory()->withOwner()->withMember($member)->create();

    expect($member->can('create', [Settlement::class, $household]))->toBeTrue();
});

test('former member cannot create a settlement in a household they left', function () {
    $member = User::factory()->create();
    $household = Household::factory()->withOwner()->withMember($member, leftAt: now())->create();

    expect($member->can('create', [Settlement::class, $household]))->toBeFalse();
});

test('non-member cannot create a settlement in another household', function () {
    $outsider = User::factory()->create();
    $household = Household::factory()->withOwner()->create();

    expect($outsider->can('create', [Settlement::class, $household]))->toBeFalse();
});

test('member cannot create a settlement in an archived household', function () {
    $member = User::factory()->create();
    $household = Household::factory()->archived()->withMember($member)->create();

    expect($member->can('create', [Settlement::class, $household]))->toBeFalse();
});
