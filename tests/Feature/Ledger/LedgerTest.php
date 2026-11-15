<?php

use App\Enums\AuditEvent;
use App\Ledger\Ledger;
use App\Ledger\UnbalancedEntry;
use App\Models\Audit;
use App\Models\Expense;
use App\Models\Household;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\QueryException;

function ledgerFixture(): array
{
    [$a, $b, $c] = User::factory()->count(3)->create()->sortBy('id')->values()->all();
    $household = Household::factory()->withOwner($a)->withMember($b)->withMember($c)->create();
    $expense = Expense::factory()->for($household)->for($a, 'payer')->create();

    return [$household, $expense, $a, $b, $c];
}

test('posts one line per user and the entry sums to zero', function () {
    [$household, $expense, $a, $b, $c] = ledgerFixture();

    $entry = Ledger::post($household, $expense, 'Rent', [$a->id => 3000, $b->id => -1500, $c->id => -1500]);

    expect($entry->lines()->count())->toBe(3)
        ->and((int) $entry->lines()->sum('amount'))->toBe(0)
        ->and($entry->source->is($expense))->toBeTrue();
});

test('refuses an entry that does not sum to zero, and writes nothing', function () {
    [$household, $expense, $a, $b] = ledgerFixture();

    expect(fn () => Ledger::post($household, $expense, 'Broken', [$a->id => 1000, $b->id => -999]))
        ->toThrow(UnbalancedEntry::class);

    expect(JournalEntry::count())->toBe(0);
});

test('drops zero lines, e.g. a payer who only covered their own share', function () {
    [$household, $expense, $a] = ledgerFixture();

    $entry = Ledger::post($household, $expense, 'Own lunch', [$a->id => 0]);

    expect($entry->lines()->count())->toBe(0);
});

test('a reversal cancels the entry out and can only happen once', function () {
    [$household, $expense, $a, $b, $c] = ledgerFixture();
    $entry = Ledger::post($household, $expense, 'Rent', [$a->id => 3000, $b->id => -1500, $c->id => -1500]);

    $reversal = Ledger::reverse($entry, 'Reversal: Rent');

    expect($reversal->reverses->is($entry))->toBeTrue()
        ->and(Ledger::balances($household))->toBe([$a->id => 0, $b->id => 0, $c->id => 0])
        ->and(fn () => Ledger::reverse($entry, 'Again'))->toThrow(QueryException::class);
});

test('the active entry is the latest one that has not been reversed', function () {
    [$household, $expense, $a, $b] = ledgerFixture();
    $first = Ledger::post($household, $expense, 'v1', [$a->id => 100, $b->id => -100]);

    expect(Ledger::activeEntryFor($expense)->is($first))->toBeTrue();

    Ledger::reverse($first, 'Reversal');
    expect(Ledger::activeEntryFor($expense))->toBeNull();

    $second = Ledger::post($household, $expense, 'v2', [$a->id => 200, $b->id => -200]);
    expect(Ledger::activeEntryFor($expense)->is($second))->toBeTrue();
});

test('balances are the sum of each user\'s lines, as integers', function () {
    [$household, $expense, $a, $b, $c] = ledgerFixture();
    Ledger::post($household, $expense, 'Rent', [$a->id => 3000, $b->id => -1500, $c->id => -1500]);
    Ledger::post($household, $expense, 'Food', [$b->id => 600, $a->id => -300, $c->id => -300]);

    expect(Ledger::balances($household))->toBe([$a->id => 2700, $b->id => -900, $c->id => -1800]);
});

test('posted entries and audits are immutable', function () {
    [$household, $expense, $a, $b] = ledgerFixture();
    $entry = Ledger::post($household, $expense, 'Rent', [$a->id => 100, $b->id => -100]);
    $audit = Audit::record($a, $household, $expense, AuditEvent::Created, ['amount' => [null, 100]]);

    expect(fn () => $entry->update(['description' => 'Changed']))->toThrow(LogicException::class)
        ->and(fn () => $entry->lines()->first()->delete())->toThrow(LogicException::class)
        ->and(fn () => $audit->delete())->toThrow(LogicException::class);
});
