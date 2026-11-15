<?php

namespace App\Ledger;

/**
 * A split that can't be applied: amounts don't add up, nobody is sharing, negative values...
 * The message is safe to show to users.
 */
class InvalidSplit extends InvalidExpense {}
