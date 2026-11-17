<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base for events meant for one person (private `App.Models.User.{id}` channel), e.g. "Bob says
 * he paid you". Same rules as HouseholdBroadcast: plain values, sent after commit, a nudge only.
 */
abstract class UserBroadcast implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly int $userId) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("App.Models.User.{$this->userId}")];
    }
}
