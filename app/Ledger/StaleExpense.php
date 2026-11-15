<?php

namespace App\Ledger;

use RuntimeException;

/**
 * Someone else changed the expense since it was loaded (optimistic locking on `version`).
 */
class StaleExpense extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('Someone else changed this bill while you were editing it. Reload to see their changes.'));
    }
}
