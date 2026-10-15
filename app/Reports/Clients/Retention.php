<?php

namespace App\Reports\Clients;

use App\Enums\ServiceStatus;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportTable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** How long services last before they end, and how old the running ones are, by product. */
class Retention extends Report
{
    public function key(): string
    {
        return 'retention';
    }

    public function title(): string
    {
        return 'Retention time';
    }

    public function group(): string
    {
        return ReportRegistry::CLIENTS;
    }

    public function description(): string
    {
        return 'How many months services lasted before they ended in these dates, and how long active ones have run, by product.';
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $today = CarbonImmutable::today();
        $products = [];
        $totals = ['ended' => [], 'active' => []];

        $rows = DB::table('services')
            ->join('clients', 'clients.id', '=', 'services.client_id')
            ->join('products', 'products.id', '=', 'services.product_id')
            ->where('clients.currency', $filters->currency)
            ->where(fn ($query) => $query
                ->where('services.status', ServiceStatus::Active->value)
                ->orWhere(fn ($ended) => $ended->whereIn('services.status', [ServiceStatus::Terminated->value, ServiceStatus::Cancelled->value])->whereBetween('services.terminated_at', $filters->between())))
            ->orderBy('products.name')
            ->select(['products.id', 'products.name', 'services.status', 'services.registration_date', 'services.terminated_at'])
            ->cursor();

        foreach ($rows as $row) {
            $kind = $row->status === ServiceStatus::Active->value ? 'active' : 'ended';
            $end = $kind === 'active' ? $today : CarbonImmutable::parse($row->terminated_at);
            $months = max(0, CarbonImmutable::parse($row->registration_date)->diffInMonths($end));
            $products[$row->id] ??= ['product' => $row->name, 'ended' => [], 'active' => []];
            $products[$row->id][$kind][] = $months;
            $totals[$kind][] = $months;
        }

        $table = new ReportTable([
            'product' => ['Product', ReportTable::TEXT],
            'ended' => ['Ended in these dates', ReportTable::NUMBER],
            'ended_months' => ['Average months before ending', ReportTable::NUMBER],
            'active' => ['Active now', ReportTable::NUMBER],
            'active_months' => ['Average months active so far', ReportTable::NUMBER],
        ], currency: $filters->currency);
        $table->chart = 'ended_months';

        foreach ($products as $product) {
            $table->add($this->row($product['product'], $product['ended'], $product['active']));
        }
        $table->totals = $this->row('All products', $totals['ended'], $totals['active']);

        return (new ReportResult)->table($table)
            ->note('Months run from each service\'s registration date to the day it was cancelled or terminated, or to today for active ones.');
    }

    /**
     * @param  list<float>  $ended
     * @param  list<float>  $active
     * @return array<string, mixed>
     */
    private function row(string $name, array $ended, array $active): array
    {
        return [
            'product' => $name,
            'ended' => count($ended),
            'ended_months' => $ended ? round(array_sum($ended) / count($ended), 1) : null,
            'active' => count($active),
            'active_months' => $active ? round(array_sum($active) / count($active), 1) : null,
        ];
    }
}
