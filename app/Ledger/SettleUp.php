<?php

namespace App\Ledger;

/**
 * Suggests who should pay whom to bring every balance to zero.
 *
 * Greedy: repeatedly match the person who owes the most with the person owed the most.
 * That needs at most n - 1 payments, but isn't guaranteed to be the minimum possible;
 * finding the true minimum is NP-hard. A documented trade-off (CLAUDE.md, Ledger rules).
 */
class SettleUp
{
    /**
     * @param  array<int, int>  $balances  user id => signed pence (positive = owed money); must sum to zero
     * @return list<array{from: int, to: int, amount: int}>
     */
    public static function transfers(array $balances): array
    {
        if (array_sum($balances) !== 0) {
            throw new UnbalancedEntry('Balances must sum to zero to settle up.');
        }

        $debtors = array_filter($balances, fn (int $balance) => $balance < 0);
        $creditors = array_filter($balances, fn (int $balance) => $balance > 0);

        $transfers = [];

        while ($debtors !== [] && $creditors !== []) {
            $from = self::largest(array_map('abs', $debtors));
            $to = self::largest($creditors);
            $amount = min(-$debtors[$from], $creditors[$to]);

            $transfers[] = ['from' => $from, 'to' => $to, 'amount' => $amount];

            $debtors[$from] += $amount;
            $creditors[$to] -= $amount;

            if ($debtors[$from] === 0) {
                unset($debtors[$from]);
            }

            if ($creditors[$to] === 0) {
                unset($creditors[$to]);
            }
        }

        return $transfers;
    }

    /**
     * The user with the biggest amount; ties go to the lowest user id, so results are repeatable.
     *
     * @param  array<int, int>  $amounts
     */
    private static function largest(array $amounts): int
    {
        $best = null;

        foreach ($amounts as $userId => $amount) {
            if ($best === null || $amount > $amounts[$best] || ($amount === $amounts[$best] && $userId < $best)) {
                $best = $userId;
            }
        }

        return (int) $best;
    }
}
