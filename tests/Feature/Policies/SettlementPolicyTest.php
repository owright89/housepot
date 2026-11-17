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

describe('responding', function () {
    test('only the recipient can confirm or reject a payment', function () {
        [$household, $alice, $bob, $cara] = flat();
        $settlement = Settlement::factory()->for($household)->create([
            'from_user_id' => $bob->id,
            'to_user_id' => $alice->id,
        ]);

        expect($alice->can('respond', $settlement))->toBeTrue()
            ->and($bob->can('respond', $settlement))->toBeFalse()
            ->and($cara->can('respond', $settlement))->toBeFalse();
    });

    test('a recipient who has left cannot respond', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $household = Household::factory()->withOwner()->withMember($alice, leftAt: now())->withMember($bob)->create();
        $settlement = Settlement::factory()->for($household)->create([
            'from_user_id' => $bob->id,
            'to_user_id' => $alice->id,
        ]);

        expect($alice->can('respond', $settlement))->toBeFalse();
    });

    test('nobody can respond in an archived household', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $household = Household::factory()->archived()->withOwner($alice)->withMember($bob)->create();
        $settlement = Settlement::factory()->for($household)->create([
            'from_user_id' => $bob->id,
            'to_user_id' => $alice->id,
        ]);

        expect($alice->can('respond', $settlement))->toBeFalse();
    });
});
