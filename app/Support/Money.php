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
     * Parse what a user typed ("12", "12.5", "£1,234.50") into pence, or null if it isn't
     * a valid positive-or-zero amount. String and integer maths only: no floats.
     */
    public static function parse(string $input): ?int
    {
        $input = trim(str_replace(' ', '', $input));

        $pattern = '/^[£€$]?(?<pounds>\d{1,3}(?:,\d{3})+|\d+)(?:\.(?<pence>\d{1,2}))?$/u';

        if (preg_match($pattern, $input, $matches) !== 1) {
            return null;
        }

        $pounds = str_replace(',', '', $matches['pounds']);
        $pence = str_pad($matches['pence'] ?? '', 2, '0');

        if (strlen($pounds) > 12) {
            return null;
        }

        return (int) $pounds * 100 + (int) $pence;
    }

    /**
     * Pence as a plain input value, e.g. 1250 => "12.50". Integer maths only.
     */
    public static function toInput(int $pence): string
    {
        return sprintf('%d.%02d', intdiv($pence, 100), $pence % 100);
    }

    /**
     * Like format(), but positive amounts get a leading "+" (e.g. "+£75.70").
     */
    public static function signed(int $pence, string $currency = 'GBP'): string
    {
        return ($pence > 0 ? '+' : '').self::format($pence, $currency);
    }
}
