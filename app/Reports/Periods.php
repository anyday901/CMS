<?php

namespace App\Reports;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** The months and days of a report's range, so empty ones still get a row. */
class Periods
{
    /** @return list<string> "2026-10" for each month from the one $from is in to the one $to is in */
    public static function months(CarbonInterface $from, CarbonInterface $to): array
    {
        $months = [];
        for ($month = CarbonImmutable::parse($from)->startOfMonth(); $month->lessThanOrEqualTo($to); $month = $month->addMonthNoOverflow()) {
            $months[] = $month->format('Y-m');
        }

        return $months;
    }

    /** @return list<string> "2026-10-15" for each day of the range */
    public static function days(CarbonInterface $from, CarbonInterface $to): array
    {
        $days = [];
        for ($day = CarbonImmutable::parse($from)->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    /** "Oct 2026" for "2026-10". */
    public static function monthLabel(string $month): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $month)->format('M Y');
    }

    /** Whole minutes between two times, or null when either is missing. */
    public static function minutes(?CarbonInterface $start, ?CarbonInterface $end): ?int
    {
        return $start && $end ? max(0, (int) round($start->diffInMinutes($end, true))) : null;
    }

    /**
     * The middle value of a list, or null for an empty one.
     *
     * @param  list<int|float>  $values
     */
    public static function median(array $values): int|float|null
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
