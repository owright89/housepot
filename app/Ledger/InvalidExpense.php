<?php

namespace App\Ledger;

use InvalidArgumentException;

/**
 * An expense that can't be recorded as given. The message is safe to show to users.
 */
class InvalidExpense extends InvalidArgumentException {}
