<?php

namespace App\Events;

class ExpenseDeleted extends ExpenseActivity
{
    public function broadcastAs(): string
    {
        return 'expense.deleted';
    }
}
