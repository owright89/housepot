<?php

namespace App\Events;

use App\Models\Settlement;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * Sent to the whole household: a payment was confirmed, so it now counts in everyone's balance.
 */
class SettlementConfirmed extends HouseholdBroadcast
{
    public readonly int $settlementId;

    public readonly int $actorId;

    public readonly string $actorName;

    public readonly string $fromName;

    public readonly string $amount;

    public function __construct(Settlement $settlement, User $actor)
    {
        parent::__construct($settlement->household_id);

        $this->settlementId = $settlement->id;
        $this->actorId = $actor->id;
        $this->actorName = Str::before($actor->name, ' ');
        $this->fromName = Str::before($settlement->fromUser->name, ' ');
        $this->amount = Money::format($settlement->amount, $settlement->household->currency);
    }

    public function broadcastAs(): string
    {
        return 'settlement.confirmed';
    }

    /**
     * @return array<string, int|string>
     */
    public function broadcastWith(): array
    {
        return [
            'settlement_id' => $this->settlementId,
            'actor_id' => $this->actorId,
            'actor_name' => $this->actorName,
            'from_name' => $this->fromName,
            'amount' => $this->amount,
        ];
    }
}
