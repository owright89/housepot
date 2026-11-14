<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\Household;
use App\Models\User;

class ExpensePolicy
{
    /**
     * Determine whether the user can view the expense.
     */
    public function view(User $user, Expense $expense): bool
    {
        return $expense->household->hasMember($user);
    }

    /**
     * Determine whether the user can record an expense in the household.
     *
     * Call with the household: $user->can('create', [Expense::class, $household]).
     */
    public function create(User $user, Household $household): bool
    {
        return $this->canChange($user, $household);
    }

    /**
     * Determine whether the user can update the expense.
     */
    public function update(User $user, Expense $expense): bool
    {
        return $this->canChange($user, $expense->household);
    }

    /**
     * Determine whether the user can delete the expense.
     */
    public function delete(User $user, Expense $expense): bool
    {
        return $this->canChange($user, $expense->household);
    }

    /**
     * Any current member can change expenses, unless the household is archived (read-only).
     */
    private function canChange(User $user, Household $household): bool
    {
        if ($household->isArchived()) {
            return false;
        }

        return $household->hasMember($user);
    }
}
