<?php

use App\Actions\Expenses\DeleteExpense;
use App\Actions\Expenses\ExpenseData;
use App\Actions\Expenses\RecordExpense;
use App\Actions\Settlements\ConfirmSettlement;
use App\Actions\Settlements\RequestSettlement;
use App\Enums\ExpenseCategory;
use App\Ledger\SplitCalculator;
use App\Models\Expense;
use App\Models\Household;
use App\Models\User;
use App\Reports\MonthlySpending;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

beforeEach(fn () => travelTo(now()->setDate(2026, 11, 17)));

function spend(Household $household, User $payer, int $amount, array $userIds, string $date, ?ExpenseCategory $category = ExpenseCategory::Other, string $description = 'Bill'): Expense
{
    return app(RecordExpense::class)->handle($payer, $household, new ExpenseData(
        $payer, $description, $amount, now()->parse($date), SplitCalculator::equal($amount, $userIds, $payer->id), $category,
    ));
}

test('totals the last six months by category, oldest first, with empty months as zero', function () {
    [$household, $alice, $bob] = flat();
    spend($household, $alice, 50000, [$alice->id, $bob->id], '2026-11-01', ExpenseCategory::Rent);
    spend($household, $bob, 9000, [$alice->id, $bob->id], '2026-11-12', ExpenseCategory::Utilities);
    spend($household, $alice, 3000, [$alice->id, $bob->id], '2026-09-30', ExpenseCategory::Internet);

    $months = MonthlySpending::for($household)->months;

    expect(array_column($months, 'key'))->toBe(['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'])
        ->and(array_column($months, 'total'))->toBe([0, 0, 0, 3000, 0, 59000])
        ->and($months[5]['categories'])->toBe(['rent' => 50000, 'utilities' => 9000]);
});

test('the total matches a direct SQL sum', function () {
    [$household, $alice, $bob, $cara] = flat();
    spend($household, $alice, 12345, [$alice->id, $bob->id, $cara->id], '2026-10-03');
    spend($household, $cara, 6789, [$bob->id, $cara->id], '2026-10-28', ExpenseCategory::Groceries);

    $sql = (int) DB::table('expenses')->where('household_id', $household->id)->whereNull('deleted_at')
        ->whereBetween('spent_on', ['2026-10-01', '2026-10-31'])->sum('amount');

    expect(MonthlySpending::for($household)->month('2026-10')['total'])->toBe($sql)->toBe(19134);
});

test('your share sums only your splits', function () {
    [$household, $alice, $bob] = flat();
    spend($household, $alice, 1001, [$alice->id, $bob->id], '2026-11-05');

    // 1001 split two ways: Alice paid, so she takes the spare penny.
    expect(MonthlySpending::for($household, $alice)->month('2026-11')['total'])->toBe(501)
        ->and(MonthlySpending::for($household, $bob)->month('2026-11')['total'])->toBe(500);
});

test('deleted bills, older bills, other households and settlements are not spending', function () {
    [$household, $alice, $bob] = flat();
    $deleted = spend($household, $alice, 4000, [$alice->id, $bob->id], '2026-11-02');
    app(DeleteExpense::class)->handle($alice, $deleted, 1);
    spend($household, $alice, 7000, [$alice->id, $bob->id], '2026-05-31');
    [$otherHousehold, $dan] = flat();
    spend($otherHousehold, $dan, 8000, [$dan->id], '2026-11-02');
    $payment = app(RequestSettlement::class)->handle($bob, $household, $alice, 2000);
    app(ConfirmSettlement::class)->handle($alice, $payment);

    expect(array_sum(array_column(MonthlySpending::for($household)->months, 'total')))->toBe(0);
});

test('bills from before categories count as Other', function () {
    [$household, $alice, $bob] = flat();
    spend($household, $alice, 2000, [$alice->id, $bob->id], '2026-11-03', category: null);

    expect(MonthlySpending::for($household)->month('2026-11')['categories'])->toBe(['other' => 2000]);
});

test('an unknown month falls back to the latest', function () {
    [$household] = flat();

    expect(MonthlySpending::for($household)->month('1999-01')['key'])->toBe('2026-11')
        ->and(MonthlySpending::for($household)->month(null)['key'])->toBe('2026-11');
});

test('axis ticks are clean pound values covering the biggest month', function (int $max, array $ticks) {
    [$household, $alice] = flat();

    if ($max > 0) {
        spend($household, $alice, $max, [$alice->id], '2026-11-01');
    }

    expect(MonthlySpending::for($household)->axisTicks())->toBe($ticks);
})->with([
    'nothing spent' => [0, [0, 10000, 20000, 30000, 40000]],
    '£590 stops at £600' => [59000, [0, 20000, 40000, 60000]],
    '£750 stops at £800' => [75000, [0, 20000, 40000, 60000, 80000]],
    '£1,050 stops at £1,500, not £2,000' => [105000, [0, 50000, 100000, 150000]],
    '£12.34 rounds up to whole pounds' => [1234, [0, 500, 1000, 1500]],
    '£2,000 exactly' => [200000, [0, 50000, 100000, 150000, 200000]],
    '£3' => [300, [0, 100, 200, 300]],
]);

test('the dashboard shows the chart and switches between household and your share', function () {
    [$household, $alice, $bob] = flat();
    spend($household, $alice, 50000, [$alice->id, $bob->id], '2026-11-01', ExpenseCategory::Rent);
    spend($household, $alice, 3000, [$alice->id, $bob->id], '2026-10-10', ExpenseCategory::Internet);
    actingAs($bob);

    $page = Livewire::test('pages::dashboard')
        ->assertSeeHtml('data-test="spending-card"')
        ->assertSee('All household bills, last 6 months')
        ->assertSee('November 2026: £500.00');

    expect(breakdown($page))->toBe('November 2026 £500.00 Rent £500.00 · 100%');

    $page->set('spendingView', 'mine')
        ->assertSee('Your share of bills, last 6 months')
        ->assertSee('November 2026: £250.00')
        ->call('selectSpendingMonth', '2026-10');

    expect(breakdown($page))->toBe('October 2026 £15.00 Internet £15.00 · 100%');

    $page->set('spendingView', 'nonsense')->assertSet('spendingView', 'household');
});

/**
 * The by-category list as plain text. (assertSeeInOrder after a call() checks the JSON
 * response, where £ is escaped, so read the rendered HTML instead.)
 */
function breakdown($page): string
{
    $html = $page->html();
    // From the end of the list's opening tag to the table view that follows it.
    $start = strpos($html, '>', strpos($html, 'data-test="spending-categories"')) + 1;
    $end = strpos($html, '<details', $start);

    return trim(preg_replace('/\s+/', ' ', strip_tags(substr($html, $start, $end - $start))));
}

test('the chart refreshes when a housemate adds a bill', function () {
    [$household, $alice, $bob] = flat();
    actingAs($alice);

    $page = Livewire::test('pages::dashboard')->assertSee('November 2026: £0.00');

    spend($household, $bob, 9000, [$alice->id, $bob->id], '2026-11-16', ExpenseCategory::Utilities, 'Octopus');

    $page->dispatch("echo-private:households.{$household->id},.expense.recorded", [
        'expense_id' => 1, 'actor_id' => $bob->id, 'actor_name' => 'Bob', 'description' => 'Octopus',
    ])->assertSee('November 2026: £90.00');
});
