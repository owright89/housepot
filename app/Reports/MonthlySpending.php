<?php

namespace App\Reports;

use App\Enums\ExpenseCategory;
use App\Models\Household;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Spending per month for a household's dashboard chart, split by category: either the whole
 * household's bills, or one member's shares of them.
 *
 * Read from bills (expenses and splits), not the ledger: this is what was spent. The ledger
 * answers who owes whom, and it also holds settlements, which aren't spending.
 */
final class MonthlySpending
{
    /**
     * @param  list<array{key: string, start: CarbonImmutable, total: int, categories: array<string, int>}>  $months  oldest first
     */
    private function __construct(public readonly array $months) {}

    /**
     * The last $count months up to and including the current one. Months without bills are
     * included with a total of 0, so the chart's x-axis never skips.
     *
     * @param  User|null  $shareOf  null for the household's total, or a member for their shares
     */
    public static function for(Household $household, ?User $shareOf = null, int $count = 6, ?CarbonInterface $now = null): self
    {
        $last = CarbonImmutable::parse($now ?? now())->startOfMonth();
        $first = $last->subMonths($count - 1);

        $query = DB::table('expenses')
            ->where('expenses.household_id', $household->id)
            ->whereNull('expenses.deleted_at')
            ->whereBetween('expenses.spent_on', [$first->toDateString(), $last->endOfMonth()->toDateString()])
            ->groupByRaw("to_char(expenses.spent_on, 'YYYY-MM'), expenses.category");

        if ($shareOf !== null) {
            $query->join('expense_splits', 'expense_splits.expense_id', '=', 'expenses.id')
                ->where('expense_splits.user_id', $shareOf->id)
                ->selectRaw("to_char(expenses.spent_on, 'YYYY-MM') AS month, expenses.category, SUM(expense_splits.amount) AS pence");
        } else {
            $query->selectRaw("to_char(expenses.spent_on, 'YYYY-MM') AS month, expenses.category, SUM(expenses.amount) AS pence");
        }

        // Postgres returns SUM() as a numeric string; bills from before categories count as Other.
        $totals = [];

        foreach ($query->get() as $row) {
            $category = $row->category ?? ExpenseCategory::Other->value;
            $totals[$row->month][$category] = ($totals[$row->month][$category] ?? 0) + (int) $row->pence;
        }

        $months = [];

        for ($month = $first; $month <= $last; $month = $month->addMonth()) {
            $categories = array_filter($totals[$month->format('Y-m')] ?? [], fn (int $pence) => $pence > 0);
            arsort($categories);

            $months[] = [
                'key' => $month->format('Y-m'),
                'start' => $month,
                'total' => array_sum($categories),
                'categories' => $categories,
            ];
        }

        return new self($months);
    }

    /**
     * A month by its 'Y-m' key, or the latest month when the key isn't one of ours.
     *
     * @return array{key: string, start: CarbonImmutable, total: int, categories: array<string, int>}
     */
    public function month(?string $key): array
    {
        foreach ($this->months as $month) {
            if ($month['key'] === $key) {
                return $month;
            }
        }

        $months = $this->months;
        $latest = end($months);

        if ($latest === false) {
            throw new LogicException('MonthlySpending always holds at least one month.');
        }

        return $latest;
    }

    /**
     * Clean y-axis values in pence (0, £500, £1,000, £1,500), stopping at the first tick at or
     * above the biggest month, so the tallest column fills most of the chart. Steps are 1, 2 or
     * 5 × a power of ten, in whole pounds, with at most $maxSteps steps.
     *
     * With nothing spent there's no scale to fit, so it shows £0 to £400.
     *
     * @return list<int>
     */
    public function axisTicks(int $maxSteps = 4): array
    {
        $max = max(array_column($this->months, 'total') ?: [0]);

        if ($max === 0) {
            return [0, 10000, 20000, 30000, 40000];
        }

        $pounds = intdiv($max + 99, 100);
        $magnitude = (int) (10 ** (int) floor(log10(max(1, $pounds / $maxSteps))));
        $step = $magnitude;

        foreach ([1, 2, 5, 10] as $multiple) {
            $step = $multiple * $magnitude;

            if ($step * $maxSteps >= $pounds) {
                break;
            }
        }

        $steps = intdiv($pounds + $step - 1, $step);

        return array_map(fn (int $i) => $i * $step * 100, range(0, $steps));
    }
}
