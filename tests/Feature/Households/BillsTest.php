<?php

use App\Actions\Expenses\ExpenseData;
use App\Actions\Expenses\RecordExpense;
use App\Actions\Expenses\UpdateExpense;
use App\Ledger\Ledger;
use App\Ledger\SplitCalculator;
use App\Models\Expense;
use App\Models\Household;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * @return array{0: Household, 1: User, 2: User, 3: User}
 */
function billsFlat(): array
{
    $alice = User::factory()->create(['name' => 'Alice Owner']);
    $bob = User::factory()->create(['name' => 'Bob Member']);
    $cara = User::factory()->create(['name' => 'Cara Member']);
    $household = Household::factory()->withOwner($alice)->withMember($bob)->withMember($cara)->create();

    return [$household, $alice, $bob, $cara];
}

function recordBill(Household $household, User $payer, int $amount, array $userIds, string $description = 'Rent'): Expense
{
    return app(RecordExpense::class)->handle($payer, $household, new ExpenseData(
        $payer, $description, $amount, now(), SplitCalculator::equal($amount, $userIds),
    ));
}

test('any member can add a bill split equally', function () {
    [$household, $alice, $bob, $cara] = billsFlat();
    actingAs($bob);

    Livewire::test('pages::households.show', ['household' => $household])
        ->call('addBill')
        ->assertSet('form.paidBy', $bob->id)
        ->set('form.description', 'Energy')
        ->set('form.amount', '£96.40')
        ->call('saveBill')
        ->assertHasNoErrors()
        ->assertSee('Energy');

    $expense = Expense::sole();

    expect($expense->amount)->toBe(9640)
        ->and($expense->paid_by)->toBe($bob->id)
        ->and(Ledger::balances($household))->toBe([$alice->id => -3214, $bob->id => 9640 - 3213, $cara->id => -3213]);
});

test('a bill can be split by exact amounts', function () {
    [$household, $alice, $bob, $cara] = billsFlat();
    actingAs($alice);

    Livewire::test('pages::households.show', ['household' => $household])
        ->call('addBill')
        ->set('form.description', 'Takeaway')
        ->set('form.amount', '30')
        ->set('form.split', 'exact')
        ->set("form.exactAmounts.{$alice->id}", '10')
        ->set("form.exactAmounts.{$bob->id}", '20')
        ->call('saveBill')
        ->assertHasNoErrors();

    expect(Ledger::balances($household))->toBe([$alice->id => 2000, $bob->id => -2000]);
});

test('bad input is shown next to the field and nothing is saved', function (array $input, string $errorField) {
    [$household, $alice, $bob] = billsFlat();
    actingAs($alice);

    Livewire::test('pages::households.show', ['household' => $household])
        ->call('addBill')
        ->set(array_merge(['form.description' => 'Rent', 'form.amount' => '30'], $input))
        ->call('saveBill')
        ->assertHasErrors($errorField);

    expect(Expense::count())->toBe(0);
})->with([
    'not a number' => [['form.amount' => 'thirty'], 'form.amount'],
    'zero' => [['form.amount' => '0'], 'form.amount'],
    'no name' => [['form.description' => ''], 'form.description'],
    'nobody sharing' => [['form.participants' => []], 'form.split'],
    'future date' => [['form.spentOn' => '2999-01-01'], 'form.spentOn'],
    'exact does not add up' => [['form.split' => 'exact', 'form.exactAmounts' => []], 'form.split'],
]);

test('a bill can be edited and the balances follow', function () {
    [$household, $alice, $bob] = billsFlat();
    $expense = recordBill($household, $alice, 2000, [$alice->id, $bob->id]);
    actingAs($bob);

    Livewire::test('pages::households.show', ['household' => $household])
        ->call('editBill', $expense->id)
        ->assertSet('form.amount', '20.00')
        ->assertSet('form.split', 'equal')
        ->set('form.amount', '30')
        ->call('saveBill')
        ->assertHasNoErrors();

    expect($expense->refresh()->amount)->toBe(3000)
        ->and(Ledger::balances($household))->toBe([$alice->id => 1500, $bob->id => -1500]);
});

test('editing a bill someone else just changed is refused', function () {
    [$household, $alice, $bob] = billsFlat();
    $expense = recordBill($household, $alice, 2000, [$alice->id, $bob->id]);
    actingAs($alice);

    $component = Livewire::test('pages::households.show', ['household' => $household])
        ->call('editBill', $expense->id)
        ->set('form.amount', '99');

    // Bob saves first.
    app(UpdateExpense::class)->handle($bob, $expense, 1, new ExpenseData($alice, 'Rent', 2500, now(), SplitCalculator::equal(2500, [$alice->id, $bob->id])));

    $component->call('saveBill');

    expect($expense->refresh()->amount)->toBe(2500);
});

test('a bill can be deleted after confirming', function () {
    [$household, $alice, $bob] = billsFlat();
    $expense = recordBill($household, $alice, 2000, [$alice->id, $bob->id]);
    actingAs($bob);

    Livewire::test('pages::households.show', ['household' => $household])
        ->call('confirmDelete', $expense->id)
        ->call('deleteBill')
        ->assertDontSee('data-test="month-total">£20.00', false);

    expect(Expense::count())->toBe(0)
        ->and(Ledger::balances($household))->toBe([$alice->id => 0, $bob->id => 0]);
});

test('bills in an archived household cannot be added or changed', function () {
    $alice = User::factory()->create();
    $household = Household::factory()->withOwner($alice)->create();
    $expense = recordBill($household, $alice, 1000, [$alice->id]);
    $household->forceFill(['archived_at' => now()])->save();
    actingAs($alice);

    Livewire::test('pages::households.show', ['household' => $household])->call('addBill')->assertForbidden();
    Livewire::test('pages::households.show', ['household' => $household])->call('editBill', $expense->id)->assertForbidden();
    Livewire::test('pages::households.show', ['household' => $household])->call('confirmDelete', $expense->id)->assertForbidden();
});

test('bills from another household cannot be edited', function () {
    [$household, $alice] = billsFlat();
    $other = Household::factory()->withOwner()->create();
    $foreign = recordBill($other, $other->members()->first(), 1000, [$other->members()->first()->id]);
    actingAs($alice);

    Livewire::test('pages::households.show', ['household' => $household])->call('editBill', $foreign->id)->assertNotFound();
});

test('the page shows real totals, balance and who owes who', function () {
    [$household, $alice, $bob, $cara] = billsFlat();
    recordBill($household, $alice, 3000, [$alice->id, $bob->id, $cara->id]);
    actingAs($bob);

    Livewire::test('pages::households.show', ['household' => $household])
        ->assertSeeHtml('data-test="month-total">£30.00')
        ->assertSeeHtml('data-test="your-share">£10.00')
        ->assertSeeInOrder(['Your balance', '-£10.00', 'You owe overall'])
        ->assertSeeInOrder(['Who owes who', 'You', 'owe', 'Alice', '£10.00', 'Cara', 'owes', 'Alice', '£10.00']);
});

test('bills can be filtered to the ones you paid', function () {
    [$household, $alice, $bob] = billsFlat();
    recordBill($household, $alice, 1000, [$alice->id, $bob->id], 'Broadband');
    recordBill($household, $bob, 2000, [$alice->id, $bob->id], 'Water');
    actingAs($alice);

    Livewire::test('pages::households.show', ['household' => $household])
        ->assertSee('Broadband')
        ->assertSee('Water')
        ->set('filter', 'you-paid')
        ->assertSee('Broadband')
        ->assertDontSee('Water');
});

test('a household with no bills shows the empty state', function () {
    [$household, $alice] = billsFlat();
    actingAs($alice);

    Livewire::test('pages::households.show', ['household' => $household])
        ->assertSee('No bills yet')
        ->assertSee('square. Nothing to settle');
});
