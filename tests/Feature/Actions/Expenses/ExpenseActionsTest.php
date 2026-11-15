<?php

use App\Actions\Expenses\DeleteExpense;
use App\Actions\Expenses\ExpenseData;
use App\Actions\Expenses\RecordExpense;
use App\Actions\Expenses\UpdateExpense;
use App\Enums\AuditEvent;
use App\Ledger\InvalidExpense;
use App\Ledger\Ledger;
use App\Ledger\SplitCalculator;
use App\Ledger\StaleExpense;
use App\Models\Audit;
use App\Models\Expense;
use App\Models\Household;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Alice (owner), Bob and Cara share a flat. Ids ascend in that order.
 *
 * @return array{0: Household, 1: User, 2: User, 3: User}
 */
function flat(): array
{
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);
    $cara = User::factory()->create(['name' => 'Cara']);
    $household = Household::factory()->withOwner($alice)->withMember($bob)->withMember($cara)->create();

    return [$household, $alice, $bob, $cara];
}

function bill(User $payer, int $amount, array $userIds, string $description = 'Rent'): ExpenseData
{
    return new ExpenseData($payer, $description, $amount, now(), SplitCalculator::equal($amount, $userIds));
}

/**
 * Invariant: every journal entry in the database sums to zero.
 */
function expectEveryEntryBalances(): void
{
    $unbalanced = DB::table('journal_lines')
        ->select('journal_entry_id')
        ->groupBy('journal_entry_id')
        ->havingRaw('SUM(amount) <> 0')
        ->count();

    expect($unbalanced)->toBe(0);
}

describe('recording', function () {
    test('a £30 bill paid by Alice, split three ways, leaves Alice owed £20', function () {
        [$household, $alice, $bob, $cara] = flat();

        $expense = app(RecordExpense::class)->handle($alice, $household, bill($alice, 3000, [$alice->id, $bob->id, $cara->id]));

        expect($expense->splits()->count())->toBe(3)
            ->and(Ledger::balances($household))->toBe([$alice->id => 2000, $bob->id => -1000, $cara->id => -1000]);

        expectEveryEntryBalances();
    });

    test('a 1p bill split three ways posts only two lines', function () {
        [$household, $alice, $bob, $cara] = flat();

        // Shares are 1p (Alice, lowest id), 0p, 0p. Bob paid, so only Alice owes him: two lines, not three.
        app(RecordExpense::class)->handle($bob, $household, bill($bob, 1, [$alice->id, $bob->id, $cara->id]));

        $entry = JournalEntry::sole();

        expect($entry->lines()->count())->toBe(2)
            ->and(Ledger::balances($household))->toBe([$alice->id => -1, $bob->id => 1]);

        expectEveryEntryBalances();
    });

    test('it writes a created audit with every field', function () {
        [$household, $alice, $bob] = flat();

        $expense = app(RecordExpense::class)->handle($bob, $household, bill($alice, 1000, [$alice->id, $bob->id], 'Broadband'));

        $audit = Audit::sole();

        expect($audit->event)->toBe(AuditEvent::Created)
            ->and($audit->user_id)->toBe($bob->id)
            ->and($audit->subject->is($expense))->toBeTrue()
            ->and($audit->changes['description'])->toBe([null, 'Broadband'])
            ->and($audit->changes['amount'])->toBe([null, 1000]);
    });

    test('invalid bills are rejected and nothing is written', function (Closure $makeData) {
        [$household, $alice, $bob, $cara] = flat();
        $outsider = User::factory()->create();

        expect(fn () => app(RecordExpense::class)->handle($alice, $household, $makeData($alice, $bob, $outsider)))
            ->toThrow(InvalidExpense::class);

        expect(Expense::count())->toBe(0)
            ->and(JournalEntry::count())->toBe(0)
            ->and(Audit::count())->toBe(0);
    })->with([
        'shares do not add up' => [fn ($alice, $bob) => new ExpenseData($alice, 'Rent', 1000, now(), [$alice->id => 400, $bob->id => 400])],
        'outsider pays' => [fn ($alice, $bob, $outsider) => bill($outsider, 1000, [$alice->id, $bob->id])],
        'outsider shares' => [fn ($alice, $bob, $outsider) => bill($alice, 1000, [$alice->id, $outsider->id])],
        'blank name' => [fn ($alice, $bob) => bill($alice, 1000, [$alice->id, $bob->id], '   ')],
    ]);

    test('bills cannot be added to an archived household', function () {
        $alice = User::factory()->create();
        $household = Household::factory()->archived()->withOwner($alice)->create();

        app(RecordExpense::class)->handle($alice, $household, bill($alice, 1000, [$alice->id]));
    })->throws(InvalidExpense::class);
});

describe('editing', function () {
    test('changing the amount reverses the old entry and posts a new one', function () {
        [$household, $alice, $bob, $cara] = flat();
        $expense = app(RecordExpense::class)->handle($alice, $household, bill($alice, 3000, [$alice->id, $bob->id, $cara->id]));

        app(UpdateExpense::class)->handle($bob, $expense, 1, bill($alice, 6000, [$alice->id, $bob->id, $cara->id]));

        expect(JournalEntry::count())->toBe(3)
            ->and(Ledger::balances($household))->toBe([$alice->id => 4000, $bob->id => -2000, $cara->id => -2000])
            ->and($expense->refresh()->version)->toBe(2)
            ->and($expense->amount)->toBe(6000);

        expectEveryEntryBalances();
    });

    test('changing who paid moves the balance', function () {
        [$household, $alice, $bob] = flat();
        $expense = app(RecordExpense::class)->handle($alice, $household, bill($alice, 2000, [$alice->id, $bob->id]));

        app(UpdateExpense::class)->handle($alice, $expense, 1, bill($bob, 2000, [$alice->id, $bob->id]));

        expect(Ledger::balances($household))->toBe([$alice->id => -1000, $bob->id => 1000]);
        expectEveryEntryBalances();
    });

    test('renaming does not touch the ledger but is audited', function () {
        [$household, $alice, $bob] = flat();
        $expense = app(RecordExpense::class)->handle($alice, $household, bill($alice, 2000, [$alice->id, $bob->id], 'Rnet'));

        app(UpdateExpense::class)->handle($bob, $expense, 1, bill($alice, 2000, [$alice->id, $bob->id], 'Rent'));

        $audit = Audit::where('event', AuditEvent::Updated)->sole();

        expect(JournalEntry::count())->toBe(1)
            ->and($audit->changes)->toBe(['description' => ['Rnet', 'Rent']])
            ->and($audit->user_id)->toBe($bob->id);
    });

    test('saving with no changes does nothing', function () {
        [$household, $alice, $bob] = flat();
        $expense = app(RecordExpense::class)->handle($alice, $household, bill($alice, 2000, [$alice->id, $bob->id]));

        app(UpdateExpense::class)->handle($alice, $expense, 1, bill($alice, 2000, [$alice->id, $bob->id]));

        expect($expense->refresh()->version)->toBe(1)
            ->and(Audit::count())->toBe(1)
            ->and(JournalEntry::count())->toBe(1);
    });

    test('an edit based on an old version is refused', function () {
        [$household, $alice, $bob] = flat();
        $expense = app(RecordExpense::class)->handle($alice, $household, bill($alice, 2000, [$alice->id, $bob->id]));

        app(UpdateExpense::class)->handle($bob, $expense, 1, bill($alice, 2500, [$alice->id, $bob->id]));

        expect(fn () => app(UpdateExpense::class)->handle($alice, $expense, 1, bill($alice, 9999, [$alice->id, $bob->id])))
            ->toThrow(StaleExpense::class);

        expect($expense->refresh()->amount)->toBe(2500);
    });
});

describe('deleting', function () {
    test('deleting reverses the bill, keeps the history and soft-deletes it', function () {
        [$household, $alice, $bob, $cara] = flat();
        $expense = app(RecordExpense::class)->handle($alice, $household, bill($alice, 3000, [$alice->id, $bob->id, $cara->id]));

        app(DeleteExpense::class)->handle($bob, $expense, 1);

        expect(Ledger::balances($household))->toBe([$alice->id => 0, $bob->id => 0, $cara->id => 0])
            ->and(Expense::find($expense->id))->toBeNull()
            ->and(Expense::withTrashed()->find($expense->id))->not->toBeNull()
            ->and(JournalEntry::count())->toBe(2)
            ->and(Audit::where('event', AuditEvent::Deleted)->sole()->changes['amount'])->toBe([3000, null]);

        expectEveryEntryBalances();
    });

    test('a delete based on an old version is refused', function () {
        [$household, $alice, $bob] = flat();
        $expense = app(RecordExpense::class)->handle($alice, $household, bill($alice, 2000, [$alice->id, $bob->id]));
        app(UpdateExpense::class)->handle($bob, $expense, 1, bill($alice, 2500, [$alice->id, $bob->id]));

        app(DeleteExpense::class)->handle($alice, $expense, 1);
    })->throws(StaleExpense::class);
});

test('balances match after a run of records, edits and deletes', function () {
    [$household, $alice, $bob, $cara] = flat();
    $everyone = [$alice->id, $bob->id, $cara->id];

    $rent = app(RecordExpense::class)->handle($alice, $household, bill($alice, 165000, $everyone));
    $energy = app(RecordExpense::class)->handle($bob, $household, bill($bob, 9640, $everyone, 'Energy'));
    $water = app(RecordExpense::class)->handle($cara, $household, bill($cara, 3810, $everyone, 'Water'));
    app(UpdateExpense::class)->handle($alice, $energy, 1, bill($bob, 9641, $everyone, 'Energy'));
    app(DeleteExpense::class)->handle($cara, $water, 1);

    // Rent 165000 / 3 = 55000 each. Energy 9641 / 3 = 3214 (Alice, lowest id) + 3214 (Bob) + 3213 (Cara).
    expect(Ledger::balances($household))->toBe([
        $alice->id => 165000 - 55000 - 3214,
        $bob->id => 9641 - 55000 - 3214,
        $cara->id => -55000 - 3213,
    ])->and(array_sum(Ledger::balances($household)))->toBe(0);

    expectEveryEntryBalances();
});
