<?php

use App\Actions\Expenses\ExpenseData;
use App\Actions\Expenses\RecordExpense;
use App\Actions\Settlements\ConfirmSettlement;
use App\Actions\Settlements\RejectSettlement;
use App\Actions\Settlements\RequestSettlement;
use App\Enums\SettlementStatus;
use App\Ledger\Ledger;
use App\Ledger\SplitCalculator;
use App\Models\Household;
use App\Models\Settlement;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Alice paid a £30 bill shared with Bob, so Bob owes her £15. Cara isn't on the bill.
 *
 * @return array{0: Household, 1: User, 2: User, 3: User}
 */
function owingFlat(): array
{
    $alice = User::factory()->create(['name' => 'Alice Smith']);
    $bob = User::factory()->create(['name' => 'Bob Jones']);
    $cara = User::factory()->create(['name' => 'Cara Lee']);
    $household = Household::factory()->withOwner($alice)->withMember($bob)->withMember($cara)->create(['currency' => 'GBP']);

    app(RecordExpense::class)->handle($alice, $household, new ExpenseData(
        $alice, 'Energy', 3000, now(), SplitCalculator::equal(3000, [$alice->id, $bob->id]),
    ));

    return [$household, $alice, $bob, $cara];
}

test('settle up is prefilled with what you owe', function () {
    [$household, $alice, $bob] = owingFlat();
    actingAs($bob);

    Livewire::test('pages::households.show', ['household' => $household, 'section' => 'payments'])
        ->assertSee('Settle up')
        ->call('settleUp')
        ->assertSet('payTo', $alice->id)
        ->assertSet('payAmount', '15.00');
});

test('recording a payment leaves it pending until the recipient confirms', function () {
    [$household, $alice, $bob] = owingFlat();
    actingAs($bob);

    Livewire::test('pages::households.show', ['household' => $household, 'section' => 'payments'])
        ->call('settleUp')
        ->call('recordPayment')
        ->assertHasNoErrors()
        ->assertSee('You paid Alice · waiting for them to confirm')
        ->assertDontSeeHtml('data-test="confirm-payment"');

    expect(Settlement::sole()->status)->toBe(SettlementStatus::Pending)
        ->and(Ledger::balances($household)[$bob->id])->toBe(-1500);
});

test('the recipient confirms a payment and balances update', function () {
    [$household, $alice, $bob] = owingFlat();
    $settlement = app(RequestSettlement::class)->handle($bob, $household, $alice, 1500);
    actingAs($alice);

    Livewire::test('pages::households.show', ['household' => $household, 'section' => 'payments'])
        ->assertSee('Bob says they paid you')
        ->call('confirmPayment', $settlement->id)
        ->assertDontSee('Bob says they paid you')
        ->assertSee('Everyone\'s square');

    expect($settlement->fresh()->status)->toBe(SettlementStatus::Confirmed)
        ->and(Ledger::balances($household))->toBe([$alice->id => 0, $bob->id => 0]);
});

test('the recipient can reject a payment', function () {
    [$household, $alice, $bob] = owingFlat();
    $settlement = app(RequestSettlement::class)->handle($bob, $household, $alice, 1500);
    actingAs($alice);

    Livewire::test('pages::households.show', ['household' => $household, 'section' => 'payments'])
        ->call('rejectPayment', $settlement->id)
        ->assertDontSee('Bob says they paid you');

    expect($settlement->fresh()->status)->toBe(SettlementStatus::Rejected)
        ->and(Ledger::balances($household)[$bob->id])->toBe(-1500);
});

test('only the recipient can answer a payment', function (string $method) {
    [$household, $alice, $bob, $cara] = owingFlat();
    $settlement = app(RequestSettlement::class)->handle($bob, $household, $alice, 1500);

    foreach ([$bob, $cara] as $user) {
        actingAs($user);

        Livewire::test('pages::households.show', ['household' => $household, 'section' => 'payments'])
            ->call($method, $settlement->id)
            ->assertForbidden();
    }

    expect($settlement->fresh()->status)->toBe(SettlementStatus::Pending);
})->with(['confirmPayment', 'rejectPayment']);

test('a payment needs a housemate and a valid amount', function (?string $to, string $amount, string $errorField) {
    [$household, $alice, $bob] = owingFlat();
    actingAs($bob);

    $payTo = match ($to) {
        'alice' => $alice->id,
        'bob' => $bob->id,
        default => null,
    };

    Livewire::test('pages::households.show', ['household' => $household, 'section' => 'payments'])
        ->set('payTo', $payTo)
        ->set('payAmount', $amount)
        ->call('recordPayment')
        ->assertHasErrors($errorField);

    expect(Settlement::count())->toBe(0);
})->with([
    'nobody chosen' => [null, '15', 'payTo'],
    'yourself' => ['bob', '15', 'payAmount'],
    'not a number' => ['alice', 'lots', 'payAmount'],
    'zero' => ['alice', '0', 'payAmount'],
]);

test('the recipient\'s page picks up a new payment from their private channel', function () {
    [$household, $alice, $bob] = owingFlat();
    actingAs($alice);

    $page = Livewire::test('pages::households.show', ['household' => $household, 'section' => 'payments'])
        ->assertDontSee('Bob says they paid you');

    app(RequestSettlement::class)->handle($bob, $household, $alice, 1500);

    $page->dispatch("echo-private:App.Models.User.{$alice->id},.settlement.requested", [
        'settlement_id' => 1, 'household_id' => $household->id, 'actor_id' => $bob->id, 'actor_name' => 'Bob', 'amount' => '£15.00',
    ])
        ->assertDispatched('toast-show')
        ->assertSee('Bob says they paid you');
});

test('the dashboard feed describes payments', function () {
    [$household, $alice, $bob] = owingFlat();
    $first = app(RequestSettlement::class)->handle($bob, $household, $alice, 1500);
    app(ConfirmSettlement::class)->handle($alice, $first);
    $second = app(RequestSettlement::class)->handle($bob, $household, $alice, 500);
    app(RejectSettlement::class)->handle($alice, $second);
    actingAs($alice);

    Livewire::test('pages::dashboard')
        ->assertSee('Bob recorded paying you £15.00')
        ->assertSee('You confirmed Bob&#039;s £15.00 payment', escape: false)
        ->assertSee('You didn&#039;t confirm Bob&#039;s £5.00 payment', escape: false);
});

test('the dashboard links to the household page to settle up', function () {
    [$household, $alice, $bob] = owingFlat();
    actingAs($bob);

    Livewire::test('pages::dashboard')
        ->assertSeeHtml('data-test="dashboard-settle-up"')
        ->assertSee(route('households.show', $household));
});
