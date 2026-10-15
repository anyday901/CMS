<?php

namespace App\Reports\Income;

use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportTable;
use App\Reports\Sql;
use Carbon\CarbonImmutable;

/** Income for each month, a row per year, so years compare side by side. */
class AnnualIncome extends Report
{
    public function key(): string
    {
        return 'annual-income';
    }

    public function title(): string
    {
        return 'Annual income';
    }

    public function group(): string
    {
        return ReportRegistry::INCOME;
    }

    public function description(): string
    {
        return 'Payments less refunds for each month, with a row for each year so you can compare them.';
    }

    public function defaultRange(CarbonImmutable $today): array
    {
        return [$today->subYears(2)->startOfYear(), $today->endOfYear()->startOfDay()];
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $month = Sql::month('transactions.created_at');
        $sums = Sql::by($this->payments($filters)->toBase(), $month, 'SUM(transactions.amount)');

        $columns = ['year' => ['Year', ReportTable::TEXT]];
        foreach (range(1, 12) as $m) {
            $columns[sprintf('m%02d', $m)] = [CarbonImmutable::create(2000, $m, 1)->format('M'), ReportTable::MONEY];
        }
        $columns['total'] = ['Total', ReportTable::MONEY];

        $table = new ReportTable($columns, currency: $filters->currency);

        for ($year = $filters->from->year; $year <= $filters->to->year; $year++) {
            $row = ['year' => (string) $year];
            foreach (range(1, 12) as $m) {
                $row[sprintf('m%02d', $m)] = (int) ($sums[sprintf('%d-%02d', $year, $m)] ?? 0);
            }
            $row['total'] = array_sum(array_slice($row, 1));
            $table->add($row);
        }

        return (new ReportResult)->table($table->withTotals(['year' => 'Total']))
            ->note('By the day each payment or refund was made, in the chosen years. Credit applied to invoices is left out, since the payment that added it already counted.');
    }
}
