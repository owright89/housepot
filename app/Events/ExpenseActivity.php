<?php

namespace App\Events;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Base for bill activity: who did it and to which bill, for the activity feed and toasts.
 */
abstract class ExpenseActivity extends HouseholdBroadcast
{
    public readonly int $expenseId;

    public readonly int $actorId;

    public readonly string $actorName;

    public readonly string $description;

    public function __construct(Expense $expense, User $actor)
    {
        parent::__construct($expense->household_id);

        $this->expenseId = $expense->id;
        $this->actorId = $actor->id;
        $this->actorName = Str::before($actor->name, ' ');
        $this->description = $expense->description;
    }

    /**
     * Only what the UI needs for a toast; figures are re-read from the database.
     *
     * @return array<string, int|string>
     */
    public function broadcastWith(): array
    {
        return [
            'expense_id' => $this->expenseId,
            'actor_id' => $this->actorId,
            'actor_name' => $this->actorName,
            'description' => $this->description,
        ];
    }
}
