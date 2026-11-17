<?php

use App\Actions\Settlements\ConfirmSettlement;
use App\Actions\Settlements\RejectSettlement;
use App\Actions\Settlements\RequestSettlement;
use App\Enums\AuditEvent;
use App\Enums\SettlementStatus;
use App\Events\BalancesUpdated;
use App\Ledger\InvalidSettlement;
use App\Ledger\Ledger;
use App\Models\Audit;
use App\Models\Expense;
use App\Models\Household;
use App\Models\JournalEntry;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Support\Facades\Event;

describe('confirming', function () {
    test('posts the payment to the ledger and settles the debt', function () {
        [$household, $alice, $bob] = flat();

        // Alice paid a £30 bill shared with Bob, so Bob owes her £15.
        $bill = Expense::factory()->for($household)->create(['paid_by' => $alice->id, 'amount' => 3000]);
        Ledger::post($household, $bill, 'Energy', [$alice->id => 1500, $bob->id => -1500]);

        $settlement = app(RequestSettlement::class)->handle($bob, $household, $alice, 1500);
        app(ConfirmSettlement::class)->handle($alice, $settlement);

        $entry = JournalEntry::query()->where('source_type', 'settlement')->sole();

        expect($entry->source->is($settlement))->toBeTrue()
            ->and($entry->lines()->sum('amount'))->toEqual(0)
            ->and($entry->lines()->pluck('amount', 'user_id')->all())->toEqual([
                $alice->id => -1500,
                $bob->id => 1500,
            ])
            ->and(Ledger::balances($household))->toBe([
                $alice->id => 0,
                $bob->id => 0,
            ]);
    });

    test('marks the settlement confirmed and audits it', function () {
        [$household, $alice, $bob] = flat();
        $settlement = app(RequestSettlement::class)->handle($bob, $household, $alice, 2000);

        $confirmed = app(ConfirmSettlement::class)->handle($alice, $settlement);

        expect($confirmed->status)->toBe(SettlementStatus::Confirmed)
            ->and($confirmed->confirmed_at)->not->toBeNull()
            ->and($settlement->fresh()->status)->toBe(SettlementStatus::Confirmed);

        $audit = Audit::query()->where('event', AuditEvent::Confirmed)->sole();

        expect($audit->user_id)->toBe($alice->id)
            ->and($audit->subject->is($settlement))->toBeTrue()
            ->and($audit->changes['status'])->toBe(['pending', 'confirmed']);
    });

    test('nudges everyone to reload balances', function () {
        Event::fake([BalancesUpdated::class]);
        [$household, $alice, $bob] = flat();
        $settlement = app(RequestSettlement::class)->handle($bob, $household, $alice, 2000);

        app(ConfirmSettlement::class)->handle($alice, $settlement);

        Event::assertDispatched(BalancesUpdated::class, fn (BalancesUpdated $event) => $event->householdId === $household->id);
    });
});

describe('rejecting', function () {
    test('marks the settlement rejected, audits it and leaves the ledger alone', function () {
        Event::fake([BalancesUpdated::class]);
        [$household, $alice, $bob] = flat();
        $settlement = app(RequestSettlement::class)->handle($bob, $household, $alice, 2000);

        $rejected = app(RejectSettlement::class)->handle($alice, $settlement);

        expect($rejected->status)->toBe(SettlementStatus::Rejected)
            ->and($rejected->confirmed_at)->toBeNull()
            ->and(JournalEntry::count())->toBe(0);

        $audit = Audit::query()->where('event', AuditEvent::Rejected)->sole();

        expect($audit->user_id)->toBe($alice->id)
            ->and($audit->subject->is($settlement))->toBeTrue()
            ->and($audit->changes['status'])->toBe(['pending', 'rejected']);

        Event::assertNotDispatched(BalancesUpdated::class);
    });
});

describe('answering twice', function () {
    test('a settlement that has already been answered cannot be answered again', function (string $state, string $action) {
        [$household, $alice, $bob] = flat();
        $settlement = Settlement::factory()->for($household)->{$state}()->create([
            'from_user_id' => $bob->id,
            'to_user_id' => $alice->id,
        ]);

        expect(fn () => app($action)->handle($alice, $settlement))->toThrow(InvalidSettlement::class);

        expect(JournalEntry::count())->toBe(0)
            ->and(Audit::count())->toBe(0)
            ->and($settlement->fresh()->status->value)->toBe($state);
    })->with([
        'confirmed',
        'rejected',
    ])->with([
        'confirm' => ConfirmSettlement::class,
        'reject' => RejectSettlement::class,
    ]);

    test('a stale copy cannot confirm a settlement that was rejected in another tab', function () {
        [$household, $alice, $bob] = flat();
        $settlement = app(RequestSettlement::class)->handle($bob, $household, $alice, 2000);
        $staleCopy = Settlement::find($settlement->id);

        app(RejectSettlement::class)->handle($alice, $settlement);

        expect(fn () => app(ConfirmSettlement::class)->handle($alice, $staleCopy))->toThrow(InvalidSettlement::class);
        expect(JournalEntry::count())->toBe(0);
    });
});

test('settlements cannot be answered in an archived household', function (string $action) {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $household = Household::factory()->withOwner($alice)->withMember($bob)->create();
    $settlement = app(RequestSettlement::class)->handle($bob, $household, $alice, 2000);
    $household->forceFill(['archived_at' => now()])->save();

    expect(fn () => app($action)->handle($alice, $settlement->fresh()))->toThrow(InvalidSettlement::class);
    expect($settlement->fresh()->status)->toBe(SettlementStatus::Pending);
})->with([
    'confirm' => ConfirmSettlement::class,
    'reject' => RejectSettlement::class,
]);
