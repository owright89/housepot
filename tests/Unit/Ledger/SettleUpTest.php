<?php

use App\Ledger\SettleUp;
use App\Ledger\UnbalancedEntry;

test('one debtor pays one creditor', function () {
    expect(SettleUp::transfers([1 => 1000, 2 => -1000]))->toBe([['from' => 2, 'to' => 1, 'amount' => 1000]]);
});

test('largest debtor pays largest creditor first', function () {
    // Alice is owed 20, Bob owed 5; Cara owes 15, Dan owes 10.
    expect(SettleUp::transfers([1 => 2000, 2 => 500, 3 => -1500, 4 => -1000]))->toBe([
        ['from' => 3, 'to' => 1, 'amount' => 1500],
        ['from' => 4, 'to' => 1, 'amount' => 500],
        ['from' => 4, 'to' => 2, 'amount' => 500],
    ]);
});

test('nobody pays when everyone is square', function () {
    expect(SettleUp::transfers([1 => 0, 2 => 0]))->toBe([])
        ->and(SettleUp::transfers([]))->toBe([]);
});

test('ties are broken by lowest user id', function () {
    expect(SettleUp::transfers([5 => 100, 3 => 100, 9 => -200]))->toBe([
        ['from' => 9, 'to' => 3, 'amount' => 100],
        ['from' => 9, 'to' => 5, 'amount' => 100],
    ]);
});

test('uses at most n - 1 payments and clears every balance', function (array $balances) {
    $transfers = SettleUp::transfers($balances);

    foreach ($transfers as $transfer) {
        $balances[$transfer['from']] += $transfer['amount'];
        $balances[$transfer['to']] -= $transfer['amount'];
    }

    expect(count($transfers))->toBeLessThanOrEqual(count($balances) - 1)
        ->and(array_filter($balances))->toBe([]);
})->with([
    [[1 => 11820, 2 => -4250, 3 => -7570]],
    [[1 => 7, 2 => 3, 3 => -5, 4 => -4, 5 => -1]],
    [[1 => -1, 2 => -1, 3 => -1, 4 => 3]],
]);

test('refuses balances that do not sum to zero', function () {
    SettleUp::transfers([1 => 100, 2 => -99]);
})->throws(UnbalancedEntry::class);
