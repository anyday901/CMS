<?php

namespace App\Reports;

/**
 * One table of a report. Each column has a label and a type, which says how
 * its values are shown and written to CSV:
 *  - text, number
 *  - money: integer cents in the table's currency
 *  - percent: a number such as 12.5 for 12.5%
 *  - duration: whole minutes
 *  - month: "2026-10"; date: "2026-10-15"
 * A row can link cells with "_links" => [column => url].
 */
class ReportTable
{
    public const TEXT = 'text';

    public const NUMBER = 'number';

    public const MONEY = 'money';

    public const PERCENT = 'percent';

    public const DURATION = 'duration';

    public const MONTH = 'month';

    public const DATE = 'date';

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var array<string, mixed>|null */
    public ?array $totals = null;

    /** The number column drawn as bars beside the table, if any. */
    public ?string $chart = null;

    /** Shown when there are no rows. */
    public string $empty = 'Nothing in these dates.';

    /** @param  array<string, array{0: string, 1: string}>  $columns  key => [label, type] */
    public function __construct(public array $columns, public ?string $title = null, public ?string $currency = null) {}

    /** @param  array<string, mixed>  $row */
    public function add(array $row): static
    {
        $this->rows[] = $row;

        return $this;
    }

    /**
     * Adds a totals row with the sum of each money and number column.
     *
     * @param  array<string, mixed>  $values  values that aren't sums, such as the label or an average
     */
    public function withTotals(array $values = []): static
    {
        $totals = [];
        foreach ($this->columns as $key => [, $type]) {
            if (in_array($type, [self::MONEY, self::NUMBER], true)) {
                $totals[$key] = array_sum(array_map(fn (array $row) => $row[$key] ?? 0, $this->rows));
            }
        }

        $this->totals = $values + $totals;

        return $this;
    }

    public function type(string $key): string
    {
        return $this->columns[$key][1] ?? self::TEXT;
    }

    /** The largest value in the chart column, for scaling its bars. */
    public function chartMax(): int|float
    {
        if ($this->chart === null) {
            return 0;
        }

        return max([0, ...array_map(fn (array $row) => abs($row[$this->chart] ?? 0), $this->rows)]);
    }
}
