<?php

use App\Actions\Settlements\RequestSettlement;
use App\Enums\AuditEvent;
use App\Enums\HouseholdRole;
use App\Enums\SettlementStatus;
use App\Ledger\InvalidSettlement;
use App\Models\Audit;
use App\Models\Household;
use App\Models\JournalEntry;
use App\Models\Settlement;
use App\Models\User;

test('a request is saved as pending and does not touch the ledger', function () {
    [$household, $alice, $bob] = flat();

    $settlement = app(RequestSettlement::class)->handle($bob, $household, $alice, 2000);

    expect($settlement->status)->toBe(SettlementStatus::Pending)
        ->and($settlement->household_id)->toBe($household->id)
        ->and($settlement->from_user_id)->toBe($bob->id)
        ->and($settlement->to_user_id)->toBe($alice->id)
        ->and($settlement->amount)->toBe(2000)
        ->and($settlement->confirmed_at)->toBeNull()
        ->and(JournalEntry::count())->toBe(0);
});

test('a request is recorded in the audit trail', function () {
    [$household, $alice, $bob] = flat();

    $settlement = app(RequestSettlement::class)->handle($bob, $household, $alice, 2000);

    $audit = Audit::sole();

    expect($audit->event)->toBe(AuditEvent::Created)
        ->and($audit->household_id)->toBe($household->id)
        ->and($audit->user_id)->toBe($bob->id)
        ->and($audit->subject->is($settlement))->toBeTrue()
        ->and($audit->changes)->toHaveCount(2)
        ->and($audit->changes['to_user_id'])->toBe([null, $alice->id])
        ->and($audit->changes['amount'])->toBe([null, 2000]);
});

test('invalid requests are rejected and nothing is written', function (Closure $request) {
    [$household, $alice, $bob] = flat();

    $outsider = User::factory()->create();

    $leaver = User::factory()->create();
    $household->allMembers()->attach($leaver, ['role' => HouseholdRole::Member, 'left_at' => now()]);

    expect(fn () => $request(app(RequestSettlement::class), $household, $alice, $bob, $outsider, $leaver))
        ->toThrow(InvalidSettlement::class);

    expect(Settlement::count())->toBe(0)
        ->and(JournalEntry::count())->toBe(0)
        ->and(Audit::count())->toBe(0);
})->with([
    'paying yourself' => [fn ($action, $household, $alice, $bob) => $action->handle($bob, $household, $bob, 2000)],
    'zero amount' => [fn ($action, $household, $alice, $bob) => $action->handle($bob, $household, $alice, 0)],
    'negative amount' => [fn ($action, $household, $alice, $bob) => $action->handle($bob, $household, $alice, -100)],
    'to an outsider' => [fn ($action, $household, $alice, $bob, $outsider) => $action->handle($bob, $household, $outsider, 2000)],
    'from an outsider' => [fn ($action, $household, $alice, $bob, $outsider) => $action->handle($outsider, $household, $alice, 2000)],
    'to a former member' => [fn ($action, $household, $alice, $bob, $outsider, $leaver) => $action->handle($bob, $household, $leaver, 2000)],
]);

test('requests cannot be made in an archived household', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $household = Household::factory()->archived()->withOwner($alice)->withMember($bob)->create();

    app(RequestSettlement::class)->handle($bob, $household, $alice, 2000);
})->throws(InvalidSettlement::class);
