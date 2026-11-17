<?php

namespace App\Ledger;

use InvalidArgumentException;

/**
 * A settlement that can't be recorded as given. The message is safe to show to users.
 */
class InvalidSettlement extends InvalidArgumentException {}
