<?php

use App\Actions\Expenses\ExpenseData;
use App\Actions\Expenses\RecordExpense;
use App\Actions\Expenses\UpdateExpense;
use App\Ledger\Ledger;
use App\Ledger\SplitCalculator;
use App\Models\Expense;
use App\Models\Household;
use App\Models\JournalEntry;
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
        // 9640 / 3 leaves 1p over: Bob paid, so his share takes it (3214), not Alice's.
        ->and(Ledger::balances($household))->toBe([$alice->id => -3213, $bob->id => 9640 - 3214, $cara->id => -3213]);
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
    'nobody sharing' => [['form.participants' => []], 'form.shares'],
    'future date' => [['form.spentOn' => '2999-01-01'], 'form.spentOn'],
    'exact does not add up' => [['form.split' => 'exact', 'form.exactAmounts' => []], 'form.shares'],
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
        ->assertSeeInOrder(['Your balance', '-£10.00', 'You owe overall']);

    // Who owes who lives in the Payments section.
    Livewire::test('pages::households.show', ['household' => $household, 'section' => 'payments'])
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
        ->assertSee('No bills yet');

    Livewire::test('pages::households.show', ['household' => $household, 'section' => 'payments'])
        ->assertSee('square. Nothing to settle')
        ->assertSee('No payments yet');
});

test('a split error is shown once', function () {
    [$household, $alice, $bob] = billsFlat();
    actingAs($alice);

    $html = Livewire::test('pages::households.show', ['household' => $household])
        ->call('addBill')
        ->set('form.description', 'Energy')
        ->set('form.amount', '100')
        ->set('form.split', 'exact')
        ->set("form.exactAmounts.{$alice->id}", '20')
        ->call('saveBill')
        ->html();

    expect(substr_count($html, 'The amounts add up to 20.00, but the bill is 100.00.'))->toBe(1);
});

test('a bill split under the old lowest-id penny rule still opens as an equal split', function () {
    [$household, $alice, $bob, $cara] = billsFlat();
    // Cara paid £50; the old rule gave the 2 spare pennies to Alice and Bob (lowest ids),
    // where the new rule would give them to Cara and Alice.
    $expense = recordBill($household, $cara, 5000, [$alice->id, $bob->id, $cara->id]);
    actingAs($alice);

    Livewire::test('pages::households.show', ['household' => $household])
        ->call('editBill', $expense->id)
        ->assertSet('form.split', 'equal')
        ->assertSet('form.lowestIdPennies', true)
        ->call('saveBill')
        ->assertHasNoErrors();

    // Nothing changed, so no penny moved and no reversal was posted.
    expect(JournalEntry::count())->toBe(1)
        ->and($expense->fresh()->splits()->pluck('amount', 'user_id')->all())
        ->toEqual([$alice->id => 1667, $bob->id => 1667, $cara->id => 1666]);
});

test('a new equal split gives the spare penny to the payer', function () {
    [$household, $alice, $bob, $cara] = billsFlat();
    actingAs($cara);

    Livewire::test('pages::households.show', ['household' => $household])
        ->call('addBill')
        ->set('form.description', 'Takeaway')
        ->set('form.amount', '50')
        ->call('saveBill')
        ->assertHasNoErrors();

    // £50 three ways: Cara paid, so she and the lowest other id (Alice) take the 2 spare pennies.
    expect(Expense::sole()->splits()->pluck('amount', 'user_id')->all())
        ->toEqual([$alice->id => 1667, $bob->id => 1666, $cara->id => 1667]);

    Livewire::test('pages::households.show', ['household' => $household])
        ->call('editBill', Expense::sole()->id)
        ->assertSet('form.split', 'equal')
        ->assertSet('form.lowestIdPennies', false);
});
