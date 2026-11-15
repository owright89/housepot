<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * PLACEHOLDER DATA until step 5 (expenses + ledger) exists.
 *
 * Builds realistic-looking bills, balances and activity for the dashboard and household pages,
 * using the household's real members as payers. Delete this class, and every call to it,
 * once the ledger provides real figures.
 *
 * @phpstan-type Bill array{name: string, due: string, payer: User, total: int, share: int, status: 'due'|'upcoming'|'paid'}
 * @phpstan-type Balance array{user: User, amount: int}
 * @phpstan-type Activity array{user: User, text: string, detail: string}
 */
class DemoData
{
    /**
     * @param  Collection<int, User>  $members
     * @return list<Bill>
     */
    public static function bills(Collection $members): array
    {
        if ($members->isEmpty()) {
            return [];
        }

        $templates = [
            ['Energy (Octopus)', 'Due 18 Nov', 9640, 'due'],
            ['Broadband', 'Due 20 Nov', 3200, 'due'],
            ['Rent', 'Due 1 Dec', 165000, 'upcoming'],
            ['Council tax', 'Due 1 Dec', 15200, 'upcoming'],
            ['Water', 'Paid 10 Nov', 3810, 'paid'],
        ];

        $bills = [];

        foreach ($templates as $i => [$name, $due, $total, $status]) {
            $bills[] = [
                'name' => $name,
                'due' => $due,
                'payer' => $members->values()[$i % $members->count()],
                'total' => $total,
                'share' => intdiv($total, $members->count()),
                'status' => $status,
            ];
        }

        return $bills;
    }

    /**
     * Positive amount: that member owes the viewer. Negative: the viewer owes them.
     *
     * @param  Collection<int, User>  $members
     * @return list<Balance>
     */
    public static function balances(Collection $members, User $viewer): array
    {
        $amounts = [11820, -4250, 2310, -1675];

        return array_values($members
            ->reject(fn (User $member) => $member->is($viewer))
            ->values()
            ->map(fn (User $member, int $i) => ['user' => $member, 'amount' => $amounts[$i % count($amounts)]])
            ->all());
    }

    /**
     * @param  list<Bill>  $bills
     * @return list<Activity>
     */
    public static function activity(array $bills): array
    {
        return array_values(collect($bills)
            ->take(3)
            ->values()
            ->map(fn (array $bill, int $i) => [
                'user' => $bill['payer'],
                'text' => $bill['name'],
                'detail' => ['2 hours ago', 'Yesterday', '12 Nov'][$i],
            ])
            ->all());
    }

    /**
     * @param  list<Bill>  $bills
     */
    public static function monthTotal(array $bills): int
    {
        return array_sum(array_column($bills, 'total'));
    }

    /**
     * @param  list<Bill>  $bills
     */
    public static function yourShare(array $bills): int
    {
        return array_sum(array_column($bills, 'share'));
    }

    /**
     * @param  list<Balance>  $balances
     */
    public static function yourBalance(array $balances): int
    {
        return array_sum(array_column($balances, 'amount'));
    }

    /**
     * A pending invite for the households page banner.
     *
     * @return array{inviter: string, household: string, members: int, currency: string, when: string}
     */
    public static function pendingInvite(): array
    {
        return ['inviter' => 'Jess Taylor', 'household' => '9 Mill Lane', 'members' => 4, 'currency' => 'GBP', 'when' => '2 days ago'];
    }
}
