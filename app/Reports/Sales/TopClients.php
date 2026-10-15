<?php

namespace App\Reports\Sales;

use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportTable;
use Illuminate\Support\Facades\DB;

/** The clients who paid the most in the dates. */
class TopClients extends Report
{
    public function key(): string
    {
        return 'top-clients';
    }

    public function title(): string
    {
        return 'Top clients by income';
    }

    public function group(): string
    {
        return ReportRegistry::SALES;
    }

    public function description(): string
    {
        return 'The clients who paid the most in these dates, after refunds.';
    }

    public function options(): array
    {
        return ['limit' => ['Show', ['25' => 'Top 25', '50' => 'Top 50', '100' => 'Top 100', '500' => 'Top 500']]];
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $rows = $this->payments($filters)
            ->toBase()
            ->join('clients', 'clients.id', '=', 'transactions.client_id')
            ->groupBy('clients.id', 'clients.first_name', 'clients.last_name', 'clients.company')
            ->orderByRaw('SUM(transactions.amount) DESC')
            ->limit((int) $filters->option('limit'))
            ->get([
                'clients.id', 'clients.first_name', 'clients.last_name', 'clients.company',
                DB::raw('SUM(CASE WHEN transactions.amount > 0 THEN 1 ELSE 0 END) as payments'),
                DB::raw('SUM(CASE WHEN transactions.amount > 0 THEN transactions.amount ELSE 0 END) as paid'),
                DB::raw('SUM(CASE WHEN transactions.amount < 0 THEN -transactions.amount ELSE 0 END) as refunded'),
                DB::raw('SUM(transactions.amount) as net'),
            ]);

        $table = new ReportTable([
            'client' => ['Client', ReportTable::TEXT],
            'payments' => ['Payments', ReportTable::NUMBER],
            'paid' => ['Paid', ReportTable::MONEY],
            'refunded' => ['Refunded', ReportTable::MONEY],
            'net' => ['Income', ReportTable::MONEY],
        ], currency: $filters->currency);
        $table->chart = 'net';

        foreach ($rows as $row) {
            $name = trim("{$row->first_name} {$row->last_name}");
            $table->add([
                'client' => $row->company ? "{$name} ({$row->company})" : $name,
                'payments' => (int) $row->payments,
                'paid' => (int) $row->paid,
                'refunded' => (int) $row->refunded,
                'net' => (int) $row->net,
                '_links' => ['client' => route('admin.clients.show', $row->id)],
            ]);
        }

        return (new ReportResult)->table($table->withTotals(['client' => 'Total']))
            ->note('Payments less refunds made in these dates. Credit applied to invoices is left out.');
    }
}
