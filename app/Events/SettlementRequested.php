<?php

namespace App\Events;

use App\Models\Settlement;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * Sent to the recipient: someone says they paid you, please confirm or reject it.
 */
class SettlementRequested extends UserBroadcast
{
    public readonly int $settlementId;

    public readonly int $householdId;

    public readonly int $actorId;

    public readonly string $actorName;

    public readonly string $amount;

    public function __construct(Settlement $settlement, User $actor)
    {
        parent::__construct($settlement->to_user_id);

        $this->settlementId = $settlement->id;
        $this->householdId = $settlement->household_id;
        $this->actorId = $actor->id;
        $this->actorName = Str::before($actor->name, ' ');
        $this->amount = Money::format($settlement->amount, $settlement->household->currency);
    }

    public function broadcastAs(): string
    {
        return 'settlement.requested';
    }

    /**
     * @return array<string, int|string>
     */
    public function broadcastWith(): array
    {
        return [
            'settlement_id' => $this->settlementId,
            'household_id' => $this->householdId,
            'actor_id' => $this->actorId,
            'actor_name' => $this->actorName,
            'amount' => $this->amount,
        ];
    }
}
