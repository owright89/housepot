<?php

use App\Actions\Expenses\DeleteExpense;
use App\Actions\Expenses\ExpenseData;
use App\Actions\Expenses\RecordExpense;
use App\Actions\Expenses\UpdateExpense;
use App\Events\BalancesUpdated;
use App\Events\ExpenseDeleted;
use App\Events\ExpenseRecorded;
use App\Events\ExpenseUpdated;
use App\Ledger\InvalidExpense;
use App\Ledger\SplitCalculator;
use App\Models\Household;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Event;

function eventsFlat(): array
{
    $alice = User::factory()->create(['name' => 'Alice Smith']);
    $bob = User::factory()->create(['name' => 'Bob Jones']);
    $household = Household::factory()->withOwner($alice)->withMember($bob)->create();

    return [$household, $alice, $bob];
}

function eventsBill(User $payer, int $amount, array $ids, string $description = 'Energy'): ExpenseData
{
    return new ExpenseData($payer, $description, $amount, now(), SplitCalculator::equal($amount, $ids));
}

test('recording a bill announces it and nudges balances on the household channel', function () {
    Event::fake([ExpenseRecorded::class, BalancesUpdated::class]);
    [$household, $alice, $bob] = eventsFlat();

    $expense = app(RecordExpense::class)->handle($bob, $household, eventsBill($bob, 9640, [$alice->id, $bob->id]));

    Event::assertDispatched(ExpenseRecorded::class, function (ExpenseRecorded $event) use ($household, $bob, $expense) {
        return $event->broadcastOn() == [new PrivateChannel("households.{$household->id}")]
            && $event->broadcastAs() === 'expense.recorded'
            && $event->broadcastWith() === [
                'expense_id' => $expense->id,
                'actor_id' => $bob->id,
                'actor_name' => 'Bob',
                'description' => 'Energy',
            ];
    });

    Event::assertDispatched(BalancesUpdated::class, fn (BalancesUpdated $event) => $event->broadcastAs() === 'balances.updated'
        && $event->broadcastWith() === ['household_id' => $household->id]);
});

test('renaming a bill announces the edit but does not nudge balances', function () {
    [$household, $alice, $bob] = eventsFlat();
    $expense = app(RecordExpense::class)->handle($alice, $household, eventsBill($alice, 2000, [$alice->id, $bob->id], 'Rnet'));
    Event::fake([ExpenseUpdated::class, BalancesUpdated::class]);

    app(UpdateExpense::class)->handle($alice, $expense, 1, eventsBill($alice, 2000, [$alice->id, $bob->id], 'Rent'));

    Event::assertDispatched(ExpenseUpdated::class, fn ($event) => $event->description === 'Rent');
    Event::assertNotDispatched(BalancesUpdated::class);
});

test('changing the amount announces the edit and nudges balances', function () {
    [$household, $alice, $bob] = eventsFlat();
    $expense = app(RecordExpense::class)->handle($alice, $household, eventsBill($alice, 2000, [$alice->id, $bob->id]));
    Event::fake([ExpenseUpdated::class, BalancesUpdated::class]);

    app(UpdateExpense::class)->handle($alice, $expense, 1, eventsBill($alice, 3000, [$alice->id, $bob->id]));

    Event::assertDispatched(ExpenseUpdated::class);
    Event::assertDispatched(BalancesUpdated::class);
});

test('deleting a bill announces it and nudges balances', function () {
    [$household, $alice, $bob] = eventsFlat();
    $expense = app(RecordExpense::class)->handle($alice, $household, eventsBill($alice, 2000, [$alice->id, $bob->id], 'Water'));
    Event::fake([ExpenseDeleted::class, BalancesUpdated::class]);

    app(DeleteExpense::class)->handle($bob, $expense, 1);

    Event::assertDispatched(ExpenseDeleted::class, fn ($event) => $event->description === 'Water' && $event->actorId === $bob->id);
    Event::assertDispatched(BalancesUpdated::class);
});

test('nothing is announced when a bill is rejected', function () {
    Event::fake([ExpenseRecorded::class, BalancesUpdated::class]);
    [$household, $alice] = eventsFlat();
    $outsider = User::factory()->create();

    expect(fn () => app(RecordExpense::class)->handle($alice, $household, eventsBill($outsider, 1000, [$alice->id])))
        ->toThrow(InvalidExpense::class);

    Event::assertNothingDispatched();
});

test('household events wait for the transaction to commit', function () {
    expect(new BalancesUpdated(1))->toBeInstanceOf(ShouldDispatchAfterCommit::class);
});
