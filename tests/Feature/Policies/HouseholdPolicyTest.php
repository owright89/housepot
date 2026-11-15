<?php

use App\Models\Household;
use App\Models\User;

test('owner can view and update their household', function () {
    $owner = User::factory()->create();
    $household = Household::factory()
        ->withOwner($owner)
        ->create();

    expect($owner->can('view', $household))->toBeTrue()
        ->and($owner->can('update', $household))->toBeTrue();
});

test('member can view but cannot update their household', function () {
    $member = User::factory()->create();
    $household = Household::factory()
        ->withMember($member)
        ->create();

    expect($member->can('view', $household))->toBeTrue()
        ->and($member->can('update', $household))->toBeFalse();
});

test('former member cannot view or update their household', function () {
    $member = User::factory()->create();
    $household = Household::factory()
        ->withMember($member, leftAt: now())
        ->create();

    expect($member->can('view', $household))->toBeFalse()
        ->and($member->can('update', $household))->toBeFalse();
});

test('unrelated user cannot view or update a household they are not a member of', function () {
    $member = User::factory()->create();
    $householdOne = Household::factory()
        ->withMember($member)
        ->create();
    $householdTwo = Household::factory()->create();

    expect($member->can('view', $householdOne))->toBeTrue();
    expect($member->can('view', $householdTwo))->toBeFalse()
        ->and($member->can('update', $householdTwo))->toBeFalse();
});

test('owner can view but not update an archived household', function () {
    $owner = User::factory()->create();
    $household = Household::factory()
        ->withOwner($owner)
        ->archived()
        ->create();

    expect($owner->can('view', $household))->toBeTrue()
        ->and($owner->can('update', $household))->toBeFalse();
});

test('owner can invite a user to their household', function () {
    $owner = User::factory()->create();
    $household = Household::factory()
        ->withOwner($owner)
        ->create();

    expect($owner->can('invite', $household))->toBeTrue();
});

test('member cannot invite a user to their household', function () {
    $member = User::factory()->create();
    $household = Household::factory()
        ->withOwner()
        ->withMember($member)
        ->create();

    expect($member->can('invite', $household))->toBeFalse();
});

test('owner cannot invite a user to their archived household', function () {
    $owner = User::factory()->create();
    $household = Household::factory()
        ->withOwner($owner)
        ->archived()
        ->create();

    expect($owner->can('invite', $household))->toBeFalse();
});
