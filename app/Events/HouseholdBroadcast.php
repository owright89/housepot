<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base for events sent to everyone viewing a household (private `households.{id}` channel).
 *
 * Events are nudges, not data: listeners re-read from the database, so the socket is never
 * the source of truth. They are sent only after the surrounding transaction commits, and they
 * hold plain values (not models) so a deleted bill can still be announced from the queue.
 */
abstract class HouseholdBroadcast implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly int $householdId) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("households.{$this->householdId}")];
    }
}
