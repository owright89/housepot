<?php

use App\Actions\Expenses\ExpenseData;
use App\Actions\Expenses\RecordExpense;
use App\Ledger\SplitCalculator;
use App\Models\Household;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Alice is looking at the page; Bob adds a bill from his own browser.
 *
 * @return array{0: Household, 1: User, 2: User}
 */
function liveFlat(): array
{
    $alice = User::factory()->create(['name' => 'Alice Smith']);
    $bob = User::factory()->create(['name' => 'Bob Jones']);
    $household = Household::factory()->withOwner($alice)->withMember($bob)->create();

    return [$household, $alice, $bob];
}

function bobAddsEnergy(Household $household, User $alice, User $bob): void
{
    app(RecordExpense::class)->handle($bob, $household, new ExpenseData(
        $bob, 'Energy', 9640, now(), SplitCalculator::equal(9640, [$alice->id, $bob->id]),
    ));
}

function bobsEvent(User $bob): array
{
    return ['expense_id' => 1, 'actor_id' => $bob->id, 'actor_name' => 'Bob', 'description' => 'Energy'];
}

test('the household page shows a bill another member added once it hears about it', function () {
    [$household, $alice, $bob] = liveFlat();
    actingAs($alice);

    $page = Livewire::test('pages::households.show', ['household' => $household])
        ->assertSee('No bills yet');

    bobAddsEnergy($household, $alice, $bob);

    $page->dispatch("echo-private:households.{$household->id},.expense.recorded", bobsEvent($bob))
        ->assertDispatched('toast-show')
        ->assertSee('Energy');

    $page->dispatch("echo-private:households.{$household->id},.balances.updated", ['household_id' => $household->id])
        ->assertSee('-£48.20');
});

test('you do not get a toast for your own change', function () {
    [$household, $alice, $bob] = liveFlat();
    actingAs($bob);

    Livewire::test('pages::households.show', ['household' => $household])
        ->dispatch("echo-private:households.{$household->id},.expense.recorded", bobsEvent($bob))
        ->assertNotDispatched('toast-show');
});

test('the page re-reads its figures when the live connection comes back', function () {
    [$household, $alice, $bob] = liveFlat();
    actingAs($alice);

    $page = Livewire::test('pages::households.show', ['household' => $household]);

    bobAddsEnergy($household, $alice, $bob);

    $page->dispatch('echo-reconnected')->assertSee('Energy');
});

test('the dashboard updates live for the current household', function () {
    [$household, $alice, $bob] = liveFlat();
    actingAs($alice);

    $dashboard = Livewire::test('pages::dashboard')->assertSee('No bills yet');

    bobAddsEnergy($household, $alice, $bob);

    $dashboard->dispatch("echo-private:households.{$household->id},.expense.recorded", bobsEvent($bob))
        ->assertDispatched('toast-show')
        ->assertSee('Energy')
        ->assertSee('Bob added Energy');

    $dashboard->dispatch("echo-private:households.{$household->id},.balances.updated", ['household_id' => $household->id])
        ->assertSeeHtml('data-test="you-owe">£48.20');
});

test('a dashboard with no household still loads and listens for reconnects only', function () {
    actingAs(User::factory()->create());

    Livewire::test('pages::dashboard')
        ->assertSee('Set up your household')
        ->dispatch('echo-reconnected')
        ->assertOk();
});

test('household cards update live when balances change', function () {
    [$household, $alice, $bob] = liveFlat();
    actingAs($alice);

    $cards = Livewire::test('pages::households.index')->assertSee('£0.00');

    bobAddsEnergy($household, $alice, $bob);

    $cards->dispatch("echo-private:households.{$household->id},.balances.updated", ['household_id' => $household->id])
        ->assertSee('-£48.20');
});

test('pages show the live connection indicator', function () {
    [$household, $alice] = liveFlat();

    actingAs($alice)->get(route('households.show', $household))->assertSee('data-test="live-indicator"', false);
    actingAs($alice)->get(route('dashboard'))->assertSee('data-test="live-indicator"', false);
});
