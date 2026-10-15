<?php

namespace App\Reports\Income;

use App\Reports\Periods;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportTable;
use App\Reports\Sql;
use Illuminate\Support\Facades\DB;

/** Money in, gateway fees and refunds for each month. */
class MonthlyTransactions extends Report
{
    public function key(): string
    {
        return 'monthly-transactions';
    }

    public function title(): string
    {
        return 'Monthly transactions';
    }

    public function group(): string
    {
        return ReportRegistry::INCOME;
    }

    public function description(): string
    {
        return 'Payments, gateway fees and refunds for each month, and what was left.';
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $month = Sql::month('transactions.created_at');
        $rows = $this->payments($filters)
            ->groupBy(DB::raw($month))
            ->get([
                DB::raw("{$month} as month"),
                DB::raw('SUM(CASE WHEN transactions.amount > 0 THEN 1 ELSE 0 END) as payments'),
                DB::raw('SUM(CASE WHEN transactions.amount > 0 THEN transactions.amount ELSE 0 END) as amount_in'),
                DB::raw('SUM(transactions.fee) as fees'),
                DB::raw('SUM(CASE WHEN transactions.amount < 0 THEN -transactions.amount ELSE 0 END) as amount_out'),
            ])->keyBy('month');

        $table = new ReportTable([
            'month' => ['Month', ReportTable::MONTH],
            'payments' => ['Payments', ReportTable::NUMBER],
            'amount_in' => ['Amount in', ReportTable::MONEY],
            'fees' => ['Fees', ReportTable::MONEY],
            'amount_out' => ['Refunds', ReportTable::MONEY],
            'balance' => ['Balance', ReportTable::MONEY],
        ], currency: $filters->currency);
        $table->chart = 'balance';

        foreach (Periods::months($filters->from, $filters->to) as $key) {
            $row = $rows->get($key);
            $in = (int) ($row->amount_in ?? 0);
            $fees = (int) ($row->fees ?? 0);
            $out = (int) ($row->amount_out ?? 0);
            $table->add(['month' => $key, 'payments' => (int) ($row->payments ?? 0), 'amount_in' => $in, 'fees' => $fees, 'amount_out' => $out, 'balance' => $in - $fees - $out]);
        }

        return (new ReportResult)->table($table->withTotals(['month' => null]))
            ->note('Balance is the amount in less gateway fees and refunds. Credit applied to invoices is left out.');
    }
}
