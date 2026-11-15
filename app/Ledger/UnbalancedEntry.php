<?php

namespace App\Ledger;

use LogicException;

/**
 * A journal entry whose lines don't sum to zero. This is always a programming error:
 * double-entry means every penny owed is owed to someone.
 */
class UnbalancedEntry extends LogicException {}
