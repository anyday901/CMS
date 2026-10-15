<?php

namespace App\Reports\Sales;

use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Reports\Periods;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportTable;
use App\Reports\Sql;
use Illuminate\Support\Facades\DB;

/** New clients and new orders for each month. */
class OrdersAndClients extends Report
{
    public function key(): string
    {
        return 'new-orders-and-clients';
    }

    public function title(): string
    {
        return 'New orders and clients';
    }

    public function group(): string
    {
        return ReportRegistry::SALES;
    }

    public function description(): string
    {
        return 'New clients and new orders for each month, how many orders were accepted and what they were worth.';
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $month = Sql::month('clients.created_at');
        $clients = Sql::by(DB::table('clients')->where('currency', $filters->currency)->whereBetween('created_at', $filters->between()), $month, 'COUNT(*)');

        $month = Sql::month('orders.created_at');
        $orders = DB::table('orders')
            ->join('clients', 'clients.id', '=', 'orders.client_id')
            ->leftJoin('invoices', fn ($join) => $join->on('invoices.id', '=', 'orders.invoice_id')->where('invoices.status', '!=', InvoiceStatus::Cancelled->value))
            ->where('clients.currency', $filters->currency)
            ->whereBetween('orders.created_at', $filters->between())
            ->groupBy(DB::raw($month))
            ->get([
                DB::raw("{$month} as month"),
                DB::raw('COUNT(*) as orders'),
                DB::raw("SUM(CASE WHEN orders.status = '".OrderStatus::Active->value."' THEN 1 ELSE 0 END) as accepted"),
                DB::raw("SUM(CASE WHEN orders.status IN ('".OrderStatus::Cancelled->value."', '".OrderStatus::Fraud->value."') THEN 1 ELSE 0 END) as cancelled"),
                DB::raw('COALESCE(SUM(invoices.total), 0) as value'),
            ])->keyBy('month');

        $table = new ReportTable([
            'month' => ['Month', ReportTable::MONTH],
            'clients' => ['New clients', ReportTable::NUMBER],
            'orders' => ['Orders', ReportTable::NUMBER],
            'accepted' => ['Accepted', ReportTable::NUMBER],
            'cancelled' => ['Cancelled or fraud', ReportTable::NUMBER],
            'value' => ['Order value', ReportTable::MONEY],
        ], currency: $filters->currency);
        $table->chart = 'orders';

        foreach (Periods::months($filters->from, $filters->to) as $key) {
            $row = $orders->get($key);
            $table->add([
                'month' => $key,
                'clients' => (int) ($clients[$key] ?? 0),
                'orders' => (int) ($row->orders ?? 0),
                'accepted' => (int) ($row->accepted ?? 0),
                'cancelled' => (int) ($row->cancelled ?? 0),
                'value' => (int) ($row->value ?? 0),
            ]);
        }

        return (new ReportResult)->table($table->withTotals(['month' => null]))
            ->note('Order value is the total of each order\'s first invoice, with tax, whether or not it has been paid. Orders still pending count in Orders but not in Accepted.');
    }
}
