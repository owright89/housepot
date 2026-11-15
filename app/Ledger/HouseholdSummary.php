<?php

namespace App\Ledger;

use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The figures the dashboard and household pages show, from one viewer's point of view.
 * Balances come from the ledger; monthly totals come from this month's bills.
 */
final class HouseholdSummary
{
    /**
     * @param  array<int, int>  $balances  user id => signed pence
     * @param  list<array{from: User, to: User, amount: int}>  $transfers
     */
    private function __construct(
        public readonly int $monthTotal,
        public readonly int $monthBillCount,
        public readonly int $yourMonthShare,
        public readonly int $yourBalance,
        public readonly array $balances,
        public readonly array $transfers,
    ) {}

    public static function for(Household $household, User $viewer): self
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        $month = $household->expenses()->whereBetween('spent_on', [$monthStart, $monthEnd]);

        $yourMonthShare = (int) DB::table('expense_splits')
            ->join('expenses', 'expenses.id', '=', 'expense_splits.expense_id')
            ->where('expenses.household_id', $household->id)
            ->whereNull('expenses.deleted_at')
            ->whereBetween('expenses.spent_on', [$monthStart, $monthEnd])
            ->where('expense_splits.user_id', $viewer->id)
            ->sum('expense_splits.amount');

        $balances = Ledger::balances($household);

        $users = User::query()->whereKey(array_keys($balances))->get()->keyBy('id');

        $transfers = array_map(fn (array $transfer) => [
            'from' => $users[$transfer['from']],
            'to' => $users[$transfer['to']],
            'amount' => $transfer['amount'],
        ], SettleUp::transfers($balances));

        return new self(
            monthTotal: (int) (clone $month)->sum('amount'),
            monthBillCount: (clone $month)->count(),
            yourMonthShare: $yourMonthShare,
            yourBalance: $balances[$viewer->id] ?? 0,
            balances: $balances,
            transfers: $transfers,
        );
    }

    /**
     * Suggested payments to the viewer.
     *
     * @return list<array{from: User, to: User, amount: int}>
     */
    public function owedToYou(User $viewer): array
    {
        return array_values(array_filter($this->transfers, fn (array $t) => $t['to']->is($viewer)));
    }

    /**
     * Suggested payments from the viewer.
     *
     * @return list<array{from: User, to: User, amount: int}>
     */
    public function youOwe(User $viewer): array
    {
        return array_values(array_filter($this->transfers, fn (array $t) => $t['from']->is($viewer)));
    }
}
