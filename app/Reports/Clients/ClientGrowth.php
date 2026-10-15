<?php

namespace App\Reports\Clients;

use App\Enums\ServiceStatus;
use App\Reports\Periods;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportTable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * New and lost clients each month. A client is lost (churned) on the day
 * their last service ended, once none of their services is still running.
 */
class ClientGrowth extends Report
{
    /** Services that still count as a customer. */
    private const RUNNING = [ServiceStatus::Pending, ServiceStatus::Active, ServiceStatus::Suspended];

    public function key(): string
    {
        return 'client-growth';
    }

    public function title(): string
    {
        return 'Client growth and churn';
    }

    public function group(): string
    {
        return ReportRegistry::CLIENTS;
    }

    public function description(): string
    {
        return 'New clients and lost clients each month, and how many customers you had at the end of it.';
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $joined = DB::table('clients')->where('currency', $filters->currency)->pluck('created_at', 'id')
            ->map(fn ($at) => CarbonImmutable::parse($at)->format('Y-m'));

        $running = array_map(fn (ServiceStatus $status) => $status->value, self::RUNNING);
        $services = DB::table('services')->join('clients', 'clients.id', '=', 'services.client_id')
            ->where('clients.currency', $filters->currency)
            ->groupBy('services.client_id')
            ->selectRaw('services.client_id, SUM(CASE WHEN services.status IN ('.implode(',', array_fill(0, count($running), '?')).') THEN 1 ELSE 0 END) as running, MAX(COALESCE(services.terminated_at, services.updated_at)) as ended, MIN(services.created_at) as started', $running)
            ->get();

        // Customers are clients with a service, from the month of their first one; a client who never ordered isn't one.
        $customers = [];
        $churned = [];
        foreach ($services as $row) {
            $customers[$row->client_id] = CarbonImmutable::parse($row->started)->format('Y-m');
            if ((int) $row->running === 0) {
                $churned[$row->client_id] = CarbonImmutable::parse($row->ended)->format('Y-m');
            }
        }

        $months = Periods::months($filters->from, $filters->to);
        $first = $months[0];
        $active = count(array_filter($customers, fn ($month) => $month !== null && $month < $first))
            - count(array_filter($churned, fn ($month) => $month < $first));

        $newBy = array_count_values(array_filter($joined->all(), fn ($month) => in_array($month, $months, true)));
        $churnBy = array_count_values($churned);
        $customersBy = array_count_values(array_filter($customers));

        $table = new ReportTable([
            'month' => ['Month', ReportTable::MONTH],
            'new_clients' => ['New clients', ReportTable::NUMBER],
            'churned' => ['Lost clients', ReportTable::NUMBER],
            'customers' => ['Customers at month end', ReportTable::NUMBER],
            'churn_rate' => ['Churn rate', ReportTable::PERCENT],
        ], currency: $filters->currency);
        $table->chart = 'customers';

        foreach ($months as $month) {
            $start = $active;
            $lost = $churnBy[$month] ?? 0;
            $active += ($customersBy[$month] ?? 0) - $lost;
            $table->add([
                'month' => $month,
                'new_clients' => $newBy[$month] ?? 0,
                'churned' => $lost,
                'customers' => $active,
                'churn_rate' => $start > 0 ? round($lost / $start * 100, 1) : null,
            ]);
        }

        $table->withTotals(['month' => null, 'customers' => $active, 'churn_rate' => null]);

        return (new ReportResult)->table($table)
            ->note('Customers are clients with at least one pending, active or suspended service, counted from the month of their first service. A client is lost in the month their last service was cancelled or terminated. Churn rate is the clients lost in a month out of the customers at its start.');
    }
}
