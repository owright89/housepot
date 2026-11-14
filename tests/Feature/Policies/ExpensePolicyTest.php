<?php

use App\Models\Expense;
use App\Models\Household;
use App\Models\User;

test('member can view, create, update and delete expenses in their household', function () {
    $member = User::factory()->create();
    $household = Household::factory()->withOwner()->withMember($member)->create();
    $expense = Expense::factory()->for($household)->create();

    expect($member->can('view', $expense))->toBeTrue()
        ->and($member->can('create', [Expense::class, $household]))->toBeTrue()
        ->and($member->can('update', $expense))->toBeTrue()
        ->and($member->can('delete', $expense))->toBeTrue();
});

test('former member cannot view, create, update or delete expenses', function () {
    $member = User::factory()->create();
    $household = Household::factory()->withOwner()->withMember($member, leftAt: now())->create();
    $expense = Expense::factory()->for($household)->create();

    expect($member->can('view', $expense))->toBeFalse()
        ->and($member->can('create', [Expense::class, $household]))->toBeFalse()
        ->and($member->can('update', $expense))->toBeFalse()
        ->and($member->can('delete', $expense))->toBeFalse();
});

test('member cannot view, create, update or delete expenses in another household', function () {
    $member = User::factory()->create();
    $ownHousehold = Household::factory()->withMember($member)->create();
    $otherHousehold = Household::factory()->withOwner()->create();
    $expense = Expense::factory()->for($otherHousehold)->create();

    expect($member->can('create', [Expense::class, $ownHousehold]))->toBeTrue();

    expect($member->can('view', $expense))->toBeFalse()
        ->and($member->can('create', [Expense::class, $otherHousehold]))->toBeFalse()
        ->and($member->can('update', $expense))->toBeFalse()
        ->and($member->can('delete', $expense))->toBeFalse();
});

test('member can view but not create, update or delete expenses in an archived household', function () {
    $member = User::factory()->create();
    $household = Household::factory()->archived()->withMember($member)->create();
    $expense = Expense::factory()->for($household)->create();

    expect($member->can('view', $expense))->toBeTrue()
        ->and($member->can('create', [Expense::class, $household]))->toBeFalse()
        ->and($member->can('update', $expense))->toBeFalse()
        ->and($member->can('delete', $expense))->toBeFalse();
});
