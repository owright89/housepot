<?php

use App\Actions\Households\CreateInvite;
use App\Models\Household;
use App\Models\User;

use function Pest\Laravel\travelTo;

test('creates a usable invite to the household from the inviter', function () {
    $owner = User::factory()->create();
    $household = Household::factory()->withOwner($owner)->create();

    $invite = app(CreateInvite::class)->handle($owner, $household);

    expect($invite->exists)->toBeTrue()
        ->and($invite->household_id)->toBe($household->id)
        ->and($invite->invited_by)->toBe($owner->id)
        ->and($invite->accepted_at)->toBeNull()
        ->and($invite->revoked_at)->toBeNull()
        ->and($invite->isUsable())->toBeTrue();
});

test('invite expires seven days after it is created', function () {
    travelTo(now()->startOfSecond());

    $owner = User::factory()->create();
    $household = Household::factory()->withOwner($owner)->create();

    $invite = app(CreateInvite::class)->handle($owner, $household)->refresh();

    expect($invite->expires_at->equalTo(now()->addDays(CreateInvite::EXPIRES_AFTER_DAYS)))->toBeTrue();
});

test('invite is no longer usable once it expires', function () {
    $owner = User::factory()->create();
    $household = Household::factory()->withOwner($owner)->create();

    $invite = app(CreateInvite::class)->handle($owner, $household);

    travelTo(now()->addDays(CreateInvite::EXPIRES_AFTER_DAYS)->addSecond());

    expect($invite->isUsable())->toBeFalse();
});
