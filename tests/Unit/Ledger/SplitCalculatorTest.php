<?php

use App\Ledger\InvalidSplit;
use App\Ledger\SplitCalculator;

describe('equal', function () {
    test('splits evenly when the total divides exactly', function () {
        expect(SplitCalculator::equal(3000, [1, 2, 3]))->toBe([1 => 1000, 2 => 1000, 3 => 1000]);
    });

    test('gives leftover pennies to the lowest user ids', function () {
        expect(SplitCalculator::equal(1000, [3, 1, 2]))->toBe([1 => 334, 2 => 333, 3 => 333])
            ->and(SplitCalculator::equal(1001, [7, 4, 9]))->toBe([4 => 334, 7 => 334, 9 => 333]);
    });

    test('a 1p bill split three ways gives 1, 0, 0', function () {
        expect(SplitCalculator::equal(1, [1, 2, 3]))->toBe([1 => 1, 2 => 0, 3 => 0]);
    });

    test('ignores duplicate user ids', function () {
        expect(SplitCalculator::equal(1000, [1, 1, 2]))->toBe([1 => 500, 2 => 500]);
    });

    test('needs at least one person', function () {
        SplitCalculator::equal(1000, []);
    })->throws(InvalidSplit::class);
});

describe('exact', function () {
    test('accepts amounts that add up to the total, sorted by user id', function () {
        expect(SplitCalculator::exact(5000, [2 => 2000, 1 => 3000]))->toBe([1 => 3000, 2 => 2000]);
    });

    test('allows someone to owe nothing', function () {
        expect(SplitCalculator::exact(5000, [1 => 5000, 2 => 0]))->toBe([1 => 5000, 2 => 0]);
    });

    test('rejects amounts that do not add up', function () {
        SplitCalculator::exact(5000, [1 => 2000, 2 => 2000]);
    })->throws(InvalidSplit::class, 'The amounts add up to 40.00, but the bill is 50.00.');

    test('rejects negative amounts', function () {
        SplitCalculator::exact(1000, [1 => 1500, 2 => -500]);
    })->throws(InvalidSplit::class);
});

describe('shares', function () {
    test('splits in proportion to shares', function () {
        expect(SplitCalculator::shares(4000, [1 => 2, 2 => 1, 3 => 1]))->toBe([1 => 2000, 2 => 1000, 3 => 1000]);
    });

    test('uses largest-remainder rounding', function () {
        // 1000 * 1/3 = 333.33 each; one leftover penny goes to the lowest id on a tie.
        expect(SplitCalculator::shares(1000, [1 => 1, 2 => 1, 3 => 1]))->toBe([1 => 334, 2 => 333, 3 => 333]);

        // 100 split 1:1:1:1:2 -> 16.67, 16.67, 16.67, 16.67, 33.33: four people have the
        // largest remainder (0.67), so they get the leftover pennies, not the 2-share person.
        expect(SplitCalculator::shares(100, [1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 2]))
            ->toBe([1 => 17, 2 => 17, 3 => 17, 4 => 16, 5 => 33]);
    });

    test('someone on zero shares pays nothing', function () {
        expect(SplitCalculator::shares(900, [1 => 1, 2 => 0, 3 => 2]))->toBe([1 => 300, 2 => 0, 3 => 600]);
    });

    test('needs at least one share', function () {
        SplitCalculator::shares(1000, [1 => 0, 2 => 0]);
    })->throws(InvalidSplit::class);
});

test('every split adds up to the total', function (int $total, array $people) {
    $ids = array_keys($people);

    expect(array_sum(SplitCalculator::equal($total, $ids)))->toBe($total)
        ->and(array_sum(SplitCalculator::shares($total, $people)))->toBe($total);
})->with([
    [1, [1 => 1, 2 => 1, 3 => 1]],
    [99, [5 => 3, 2 => 1, 9 => 2]],
    [196850, [1 => 1, 2 => 1, 3 => 1]],
    [100001, [1 => 7, 2 => 3, 3 => 5, 4 => 1, 5 => 11, 6 => 2, 7 => 13]],
]);

test('a zero or negative total is rejected', function (int $total) {
    SplitCalculator::equal($total, [1, 2]);
})->throws(InvalidSplit::class)->with([0, -100]);
