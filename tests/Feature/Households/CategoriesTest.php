<?php

use App\Actions\Expenses\ExpenseData;
use App\Actions\Expenses\RecordExpense;
use App\Enums\AuditEvent;
use App\Enums\ExpenseCategory;
use App\Ledger\SplitCalculator;
use App\Models\Audit;
use App\Models\Expense;
use App\Models\JournalEntry;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

test('a new bill defaults to Other and can be given a category', function () {
    [$household, $alice, $bob] = flat();
    actingAs($alice);

    Livewire::test('pages::households.show', ['household' => $household])
        ->call('addBill')
        ->assertSet('form.category', 'other')
        ->set('form.description', 'Octopus')
        ->set('form.amount', '90')
        ->set('form.category', 'utilities')
        ->call('saveBill')
        ->assertHasNoErrors()
        ->assertSeeInOrder(['Octopus', 'Utilities']);

    expect(Expense::sole()->category)->toBe(ExpenseCategory::Utilities);
});

test('an unknown category is rejected', function () {
    [$household, $alice] = flat();
    actingAs($alice);

    Livewire::test('pages::households.show', ['household' => $household])
        ->call('addBill')
        ->set('form.description', 'Octopus')
        ->set('form.amount', '90')
        ->set('form.category', 'holidays')
        ->call('saveBill')
        ->assertHasErrors('form.category');

    expect(Expense::count())->toBe(0);
});

test('changing only the category is audited but leaves the ledger alone', function () {
    [$household, $alice, $bob] = flat();
    $expense = app(RecordExpense::class)->handle($alice, $household, new ExpenseData(
        $alice, 'Tesco', 4000, now(), SplitCalculator::equal(4000, [$alice->id, $bob->id]), ExpenseCategory::Other,
    ));
    actingAs($bob);

    Livewire::test('pages::households.show', ['household' => $household])
        ->call('editBill', $expense->id)
        ->assertSet('form.category', 'other')
        ->set('form.category', 'groceries')
        ->call('saveBill')
        ->assertHasNoErrors();

    $audit = Audit::query()->where('event', AuditEvent::Updated)->sole();

    expect($expense->fresh()->category)->toBe(ExpenseCategory::Groceries)
        ->and($audit->changes)->toBe(['category' => ['other', 'groceries']])
        ->and(JournalEntry::count())->toBe(1);
});

test('bills recorded before categories existed show as Other', function () {
    [$household, $alice] = flat();
    $expense = Expense::factory()->for($household)->create(['paid_by' => $alice->id, 'description' => 'Old bill', 'category' => null]);
    actingAs($alice);

    expect($expense->displayCategory())->toBe(ExpenseCategory::Other);

    Livewire::test('pages::households.show', ['household' => $household])
        ->assertSeeInOrder(['Old bill', 'Other'])
        ->call('editBill', $expense->id)
        ->assertSet('form.category', 'other');
});

test('the dashboard shows each recent bill\'s category', function () {
    [$household, $alice, $bob] = flat();
    app(RecordExpense::class)->handle($alice, $household, new ExpenseData(
        $alice, 'Virgin Media', 3000, now(), SplitCalculator::equal(3000, [$alice->id, $bob->id]), ExpenseCategory::Internet,
    ));
    actingAs($alice);

    Livewire::test('pages::dashboard')->assertSeeInOrder(['Virgin Media', 'Internet']);
});
