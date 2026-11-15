<?php

namespace App\Events;

class ExpenseRecorded extends ExpenseActivity
{
    public function broadcastAs(): string
    {
        return 'expense.recorded';
    }
}
