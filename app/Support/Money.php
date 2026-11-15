<?php

namespace App\Support;

use Illuminate\Support\Number;

/**
 * Formats integer minor units (pence) for display. Money is never stored as a float.
 */
class Money
{
    public static function format(int $pence, string $currency = 'GBP'): string
    {
        return (string) Number::currency($pence / 100, in: $currency, locale: 'en_GB');
    }

    /**
     * Like format(), but positive amounts get a leading "+" (e.g. "+£75.70").
     */
    public static function signed(int $pence, string $currency = 'GBP'): string
    {
        return ($pence > 0 ? '+' : '').self::format($pence, $currency);
    }
}
