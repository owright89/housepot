<?php

namespace App\Events;

class ExpenseUpdated extends ExpenseActivity
{
    public function broadcastAs(): string
    {
        return 'expense.updated';
    }
}
