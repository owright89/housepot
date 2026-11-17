<?php

use App\Actions\Settlements\ConfirmSettlement;
use App\Actions\Settlements\RejectSettlement;
use App\Actions\Settlements\RequestSettlement;
use App\Events\BalancesUpdated;
use App\Events\SettlementConfirmed;
use App\Events\SettlementRejected;
use App\Events\SettlementRequested;
use App\Models\Household;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;

/**
 * Bob pays Alice £15.
 *
 * @return array{0: Household, 1: User, 2: User}
 */
function paymentFlat(): array
{
    $alice = User::factory()->create(['name' => 'Alice Smith']);
    $bob = User::factory()->create(['name' => 'Bob Jones']);
    $household = Household::factory()->withOwner($alice)->withMember($bob)->create(['currency' => 'GBP']);

    return [$household, $alice, $bob];
}

test('recording a payment asks the recipient, on their own channel, to confirm it', function () {
    Event::fake([SettlementRequested::class, BalancesUpdated::class]);
    [$household, $alice, $bob] = paymentFlat();

    $settlement = app(RequestSettlement::class)->handle($bob, $household, $alice, 1500);

    Event::assertDispatched(SettlementRequested::class, function (SettlementRequested $event) use ($household, $alice, $bob, $settlement) {
        return $event->broadcastOn() == [new PrivateChannel("App.Models.User.{$alice->id}")]
            && $event->broadcastAs() === 'settlement.requested'
            && $event->broadcastWith() === [
                'settlement_id' => $settlement->id,
                'household_id' => $household->id,
                'actor_id' => $bob->id,
                'actor_name' => 'Bob',
                'amount' => '£15.00',
            ];
    });
    Event::assertNotDispatched(BalancesUpdated::class);
});

test('confirming a payment tells the household and nudges balances', function () {
    Event::fake([SettlementConfirmed::class, BalancesUpdated::class]);
    [$household, $alice, $bob] = paymentFlat();
    $settlement = app(RequestSettlement::class)->handle($bob, $household, $alice, 1500);

    app(ConfirmSettlement::class)->handle($alice, $settlement);

    Event::assertDispatched(SettlementConfirmed::class, function (SettlementConfirmed $event) use ($household, $alice, $settlement) {
        return $event->broadcastOn() == [new PrivateChannel("households.{$household->id}")]
            && $event->broadcastAs() === 'settlement.confirmed'
            && $event->broadcastWith() === [
                'settlement_id' => $settlement->id,
                'actor_id' => $alice->id,
                'actor_name' => 'Alice',
                'from_name' => 'Bob',
                'amount' => '£15.00',
            ];
    });
    Event::assertDispatched(BalancesUpdated::class);
});

test('rejecting a payment tells the payer on their own channel', function () {
    Event::fake([SettlementRejected::class, BalancesUpdated::class]);
    [$household, $alice, $bob] = paymentFlat();
    $settlement = app(RequestSettlement::class)->handle($bob, $household, $alice, 1500);

    app(RejectSettlement::class)->handle($alice, $settlement);

    Event::assertDispatched(SettlementRejected::class, function (SettlementRejected $event) use ($bob) {
        return $event->broadcastOn() == [new PrivateChannel("App.Models.User.{$bob->id}")]
            && $event->broadcastAs() === 'settlement.rejected'
            && $event->broadcastWith()['actor_name'] === 'Alice';
    });
    Event::assertNotDispatched(BalancesUpdated::class);
});
