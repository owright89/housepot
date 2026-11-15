<?php

namespace App\Events;

/**
 * Someone's balance in the household changed: reload balances and who owes who.
 */
class BalancesUpdated extends HouseholdBroadcast
{
    public function broadcastAs(): string
    {
        return 'balances.updated';
    }

    /**
     * @return array<string, int>
     */
    public function broadcastWith(): array
    {
        return ['household_id' => $this->householdId];
    }
}
