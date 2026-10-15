<?php

namespace App\Reports\Clients;

use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportTable;
use App\Reports\Sql;
use Illuminate\Support\Facades\DB;

/** What the services on each server bring in. */
class ServerRevenue extends Report
{
    public function key(): string
    {
        return 'server-revenue';
    }

    public function title(): string
    {
        return 'Server revenue';
    }

    public function group(): string
    {
        return ReportRegistry::CLIENTS;
    }

    public function description(): string
    {
        return 'Active services on each server, what they bill per month, and what they brought in on invoices paid in these dates.';
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $servers = DB::table('servers')->orderBy('name')->pluck('name', 'id');
        $rows = [];
        $row = function (?int $id) use (&$rows, $servers): void {
            $rows[$id ?? 0] ??= ['server' => $id === null ? 'No server' : ($servers[$id] ?? 'Deleted server'), 'services' => 0, 'monthly' => 0, 'income' => 0];
        };

        $active = DB::table('services')->join('clients', 'clients.id', '=', 'services.client_id')
            ->where('clients.currency', $filters->currency)->where('services.status', ServiceStatus::Active->value)
            ->get(['services.server_id', 'services.billing_cycle', 'services.recurring_amount', 'services.recurring_discount']);
        foreach ($active as $service) {
            $row($service->server_id === null ? null : (int) $service->server_id);
            $months = BillingCycle::tryFrom($service->billing_cycle)?->months();
            $rows[$service->server_id ?? 0]['services']++;
            $rows[$service->server_id ?? 0]['monthly'] += $months ? intdiv(max(0, $service->recurring_amount - $service->recurring_discount), $months) : 0;
        }

        $income = Sql::by(DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('services', 'services.id', '=', 'invoice_items.service_id')
            ->where('invoices.status', InvoiceStatus::Paid->value)
            ->where('invoices.currency', $filters->currency)
            ->whereBetween('invoices.paid_at', $filters->between()), 'services.server_id', 'SUM(invoice_items.amount)');
        foreach ($income as $server => $amount) {
            $id = $server === '' || $server === null ? null : (int) $server;
            $row($id);
            $rows[$id ?? 0]['income'] += (int) $amount;
        }

        // Every server shows, even with nothing on it.
        foreach ($servers->keys() as $id) {
            $row((int) $id);
        }

        $table = new ReportTable([
            'server' => ['Server', ReportTable::TEXT],
            'services' => ['Active services', ReportTable::NUMBER],
            'monthly' => ['Per month', ReportTable::MONEY],
            'income' => ['Income in these dates', ReportTable::MONEY],
        ], currency: $filters->currency);
        $table->chart = 'monthly';
        uasort($rows, fn (array $a, array $b) => $b['monthly'] <=> $a['monthly']);
        $table->rows = array_values($rows);

        return (new ReportResult)->table($table->withTotals(['server' => 'Total']))
            ->note('Per month spreads each active service\'s renewal price over its billing cycle. Income is the service\'s lines, before tax, on invoices paid in these dates, by the server the service is on today.');
    }
}
