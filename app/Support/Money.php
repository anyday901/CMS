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

    /**
     * Parses "12.34", "12", "$1,234.5" into cents without float rounding.
     * Commas must be correct thousands separators, so "12,34" is rejected.
     */
    public static function parse(string $value): int
    {
        $clean = str_replace(' ', '', trim($value));

        if (! preg_match('/^(-?)\$?(\d{1,3}(?:,\d{3})+|\d*)(?:\.(\d{1,2}))?$/', $clean, $m)
            || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new InvalidArgumentException("Invalid amount: {$value}");
        }

        $cents = (int) (str_replace(',', '', $m[2]) ?: '0') * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return $m[1] === '-' ? -$cents : $cents;
    }

    /** Validation rule for an amount that may be negative, such as a discount line. */
    public static function signedRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            try {
                static::parse((string) $value);
            } catch (InvalidArgumentException) {
                $fail('The :attribute must be an amount like 12.34 or -5.00.');
            }
        };
    }

    /** Validation rule for a non-negative amount field. */
    public static function rule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            try {
                if (static::parse((string) $value) < 0) {
                    $fail('The :attribute cannot be negative.');
                }
            } catch (InvalidArgumentException) {
                $fail('The :attribute must be an amount like 12.34.');
            }
        };
    }
}
