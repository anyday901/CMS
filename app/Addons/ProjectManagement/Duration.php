<?php

namespace App\Addons\ProjectManagement;

use Closure;
use InvalidArgumentException;

/** Turns what staff type for time ("1:30", "1.5h", "90m", "2h 15m") into minutes, and back. */
class Duration
{
    /** The most one entry can hold: a full day. */
    public const MAX_MINUTES = 1440;

    /**
     * Minutes in "1:30", "1.5h", "1.5", "90m", "90 min" or "2h 15m". A bare
     * number is hours, so "2" is two hours.
     */
    public static function parse(string $value): int
    {
        $clean = strtolower(str_replace(' ', '', trim($value)));

        $minutes = match (true) {
            (bool) preg_match('/^(\d{1,2}):([0-5]\d)$/', $clean, $m) => (int) $m[1] * 60 + (int) $m[2],
            (bool) preg_match('/^(\d+(?:\.\d+)?|\.\d+)(?:h|hr|hrs|hours?)?$/', $clean, $m) => (int) round((float) $m[1] * 60),
            (bool) preg_match('/^(\d+)(?:m|min|mins|minutes?)$/', $clean, $m) => (int) $m[1],
            (bool) preg_match('/^(\d+)(?:h|hr|hrs|hours?)(\d+)(?:m|min|mins|minutes?)?$/', $clean, $m) => (int) $m[1] * 60 + (int) $m[2],
            default => throw new InvalidArgumentException("Invalid time: {$value}"),
        };

        if ($minutes < 1 || $minutes > self::MAX_MINUTES) {
            throw new InvalidArgumentException("Time must be between a minute and 24 hours: {$value}");
        }

        return $minutes;
    }

    /** "1h 30m", "2h" or "45m". */
    public static function format(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return match (true) {
            $hours === 0 => "{$rest}m",
            $rest === 0 => "{$hours}h",
            default => "{$hours}h {$rest}m",
        };
    }

    /** Cents for this many minutes at an hourly rate, rounded half up. */
    public static function cost(int $minutes, int $hourlyRate): int
    {
        return intdiv($minutes * $hourlyRate * 2 + 60, 120);
    }

    /** Validation rule for a time field. */
    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            try {
                static::parse((string) $value);
            } catch (InvalidArgumentException) {
                $fail('Enter the time like 1:30, 1.5h or 90m (up to 24 hours).');
            }
        };
    }
}
