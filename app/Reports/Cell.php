<?php

namespace App\Reports;

use App\Support\Csv;
use App\Support\Money;
use Carbon\CarbonImmutable;

/** How a report value is shown on the page and written to CSV, by its column type. */
class Cell
{
    public static function display(mixed $value, string $type, ?string $currency): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($type) {
            ReportTable::MONEY => Money::format((int) $value, $currency ?? (string) config('billing.currency')),
            ReportTable::NUMBER => is_float($value) ? number_format($value, 1) : number_format((int) $value),
            ReportTable::PERCENT => number_format((float) $value, 1).'%',
            ReportTable::DURATION => self::duration((int) $value),
            ReportTable::MONTH => Periods::monthLabel((string) $value),
            ReportTable::DATE => CarbonImmutable::parse((string) $value)->format('M j, Y'),
            default => (string) $value,
        };
    }

    /** Plain values for a spreadsheet: amounts as 12.50, durations in minutes, text safe from formulas. */
    public static function csv(mixed $value, string $type): mixed
    {
        return match (true) {
            $value === null => '',
            $type === ReportTable::MONEY => Money::toInput((int) $value),
            $type === ReportTable::PERCENT => number_format((float) $value, 1, '.', ''),
            $type === ReportTable::NUMBER, $type === ReportTable::DURATION => $value,
            default => Csv::cell((string) $value),
        };
    }

    /** "2d 3h", "3h 20m" or "45m". */
    public static function duration(int $minutes): string
    {
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $mins = $minutes % 60;

        return match (true) {
            $days > 0 => "{$days}d {$hours}h",
            $hours > 0 => "{$hours}h {$mins}m",
            default => "{$mins}m",
        };
    }
}
