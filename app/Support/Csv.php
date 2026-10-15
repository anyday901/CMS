<?php

namespace App\Support;

/** Helpers for the CSV files staff download. */
class Csv
{
    /** Stops a spreadsheet treating a value such as "=HYPERLINK(...)" as a formula. */
    public static function cell(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
