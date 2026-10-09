<?php

namespace App\Support;

use InvalidArgumentException;
use NumberFormatter;

/** Converts between integer cents and display or form strings. */
class Money
{
    public static function format(int $cents, string $currency = 'USD'): string
    {
        $formatter = new NumberFormatter('en_US', NumberFormatter::CURRENCY);

        return $formatter->formatCurrency($cents / 100, $currency);
    }

    /** Value for a form input, e.g. 1234 -> "12.34". */
    public static function toInput(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /** Parses "12.34", "12", "$1,234.5" into cents without float rounding. */
    public static function parse(string $value): int
    {
        $clean = str_replace([',', '$', ' '], '', trim($value));

        if (! preg_match('/^(-?)(\d*)(?:\.(\d{0,2}))?$/', $clean, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new InvalidArgumentException("Invalid amount: {$value}");
        }

        $cents = (int) ($m[2] ?: '0') * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return $m[1] === '-' ? -$cents : $cents;
    }
}
