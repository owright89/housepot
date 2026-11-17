<?php

namespace App\Ledger;

/**
 * Turns a bill total (pence) into each person's share (pence).
 *
 * Every method returns shares keyed by user id, sorted by user id, that add up to the
 * total exactly. Integer maths only: money never goes through a float.
 */
class SplitCalculator
{
    /**
     * Equal split. When the total doesn't divide evenly, the leftover pennies go one each to
     * the payer first (if they share the bill), then to the lowest user ids. The payer is the
     * one holding the odd penny, so nobody else is charged extra on someone else's bill, and the
     * same people aren't always the ones rounded up. Deterministic (CLAUDE.md, Ledger rules).
     *
     * Without a payer (or when the payer isn't sharing), the pennies go by lowest user id only:
     * the rule used for bills recorded before the payer-first rule.
     *
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    public static function equal(int $total, array $userIds, ?int $payerId = null): array
    {
        self::assertPositiveTotal($total);

        $userIds = array_values(array_unique($userIds));
        sort($userIds);

        if ($userIds === []) {
            throw new InvalidSplit(__('Choose at least one person to share the bill.'));
        }

        $base = intdiv($total, count($userIds));
        $leftover = $total % count($userIds);

        $order = $userIds;

        if ($payerId !== null && in_array($payerId, $userIds, true)) {
            $order = [$payerId, ...array_values(array_diff($userIds, [$payerId]))];
        }

        $shares = [];

        foreach ($order as $i => $userId) {
            $shares[$userId] = $base + ($i < $leftover ? 1 : 0);
        }

        ksort($shares);

        return $shares;
    }

    /**
     * Exact amounts per person, which must add up to the total.
     *
     * @param  array<int, int>  $amounts  user id => pence
     * @return array<int, int>
     */
    public static function exact(int $total, array $amounts): array
    {
        self::assertPositiveTotal($total);

        if ($amounts === []) {
            throw new InvalidSplit(__('Choose at least one person to share the bill.'));
        }

        foreach ($amounts as $amount) {
            if ($amount < 0) {
                throw new InvalidSplit(__('Amounts can\'t be negative.'));
            }
        }

        $sum = array_sum($amounts);

        if ($sum !== $total) {
            throw new InvalidSplit(__('The amounts add up to :sum, but the bill is :total.', [
                'sum' => number_format($sum / 100, 2),
                'total' => number_format($total / 100, 2),
            ]));
        }

        ksort($amounts);

        return $amounts;
    }

    /**
     * Split by shares (e.g. 2 shares for the big room, 1 for the others), using
     * largest-remainder rounding: everyone gets the rounded-down amount, then the
     * leftover pennies go to the largest remainders (ties: lowest user id).
     *
     * @param  array<int, int>  $shares  user id => number of shares
     * @return array<int, int>
     */
    public static function shares(int $total, array $shares): array
    {
        self::assertPositiveTotal($total);

        foreach ($shares as $share) {
            if ($share < 0) {
                throw new InvalidSplit(__('Shares can\'t be negative.'));
            }
        }

        $totalShares = array_sum($shares);

        if ($totalShares === 0) {
            throw new InvalidSplit(__('Give at least one person a share of the bill.'));
        }

        ksort($shares);

        $amounts = [];
        $remainders = [];

        foreach ($shares as $userId => $share) {
            $amounts[$userId] = intdiv($total * $share, $totalShares);
            $remainders[$userId] = ($total * $share) % $totalShares;
        }

        $leftover = $total - array_sum($amounts);

        // Largest remainder first; ties broken by lowest user id.
        uksort($remainders, fn (int $a, int $b) => [$remainders[$b], $a] <=> [$remainders[$a], $b]);

        foreach (array_slice(array_keys($remainders), 0, $leftover) as $userId) {
            $amounts[$userId]++;
        }

        return $amounts;
    }

    private static function assertPositiveTotal(int $total): void
    {
        if ($total <= 0) {
            throw new InvalidSplit(__('The bill amount must be more than zero.'));
        }
    }
}
