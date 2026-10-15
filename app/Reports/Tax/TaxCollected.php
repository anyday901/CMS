<?php

namespace App\Reports\Tax;

use App\Enums\InvoiceStatus;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportTable;
use Illuminate\Support\Facades\DB;

/** Tax charged on invoices by country and rate, for sales tax and VAT returns. */
class TaxCollected extends Report
{
    /** The European Union's member states, for VAT returns. */
    public const EU = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'];

    public function key(): string
    {
        return 'tax-collected';
    }

    public function title(): string
    {
        return 'Tax collected';
    }

    public function group(): string
    {
        return ReportRegistry::TAX;
    }

    public function description(): string
    {
        return 'Sales and the tax charged on them by country and rate, for sales tax liability and EU VAT returns.';
    }

    public function options(): array
    {
        return [
            'basis' => ['Count invoices', ['paid' => 'Paid in these dates', 'issued' => 'Issued in these dates']],
            'region' => ['Clients in', ['all' => 'Every country', 'eu' => 'The EU only']],
        ];
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $query = DB::table('invoices')
            ->join('clients', 'clients.id', '=', 'invoices.client_id')
            ->where('invoices.currency', $filters->currency)
            ->when($filters->option('basis') === 'issued',
                fn ($query) => $query->whereIn('invoices.status', [InvoiceStatus::Unpaid->value, InvoiceStatus::Paid->value])->whereBetween('invoices.issue_date', [$filters->from->toDateString(), $filters->to->toDateString()]),
                fn ($query) => $query->where('invoices.status', InvoiceStatus::Paid->value)->whereBetween('invoices.paid_at', $filters->between()))
            ->when($filters->option('region') === 'eu', fn ($query) => $query->whereIn('clients.country', self::EU));

        $rows = $query->groupBy('clients.country', 'invoices.tax_name', 'invoices.tax_rate')
            ->orderBy('clients.country')->orderByDesc('invoices.tax_rate')
            ->get([
                'clients.country', 'invoices.tax_name', 'invoices.tax_rate',
                DB::raw('COUNT(*) as invoices'),
                DB::raw('SUM(invoices.total - invoices.tax) as sales'),
                DB::raw('SUM(invoices.tax) as tax'),
            ]);

        $table = new ReportTable([
            'country' => ['Country', ReportTable::TEXT],
            'tax_name' => ['Tax', ReportTable::TEXT],
            'rate' => ['Rate', ReportTable::PERCENT],
            'invoices' => ['Invoices', ReportTable::NUMBER],
            'sales' => ['Sales before tax', ReportTable::MONEY],
            'tax' => ['Tax', ReportTable::MONEY],
        ], currency: $filters->currency);
        $table->chart = 'tax';

        foreach ($rows as $row) {
            $rate = (int) $row->tax_rate;
            $table->add([
                'country' => $row->country ?: 'Unknown',
                'tax_name' => $rate > 0 ? ($row->tax_name ?: 'Tax') : 'No tax',
                'rate' => $rate / 100,
                'invoices' => (int) $row->invoices,
                'sales' => (int) $row->sales,
                'tax' => (int) $row->tax,
            ]);
        }

        return (new ReportResult)->table($table->withTotals(['country' => 'Total']))
            ->note($filters->option('basis') === 'issued'
                ? 'Invoices issued in these dates, paid or not (accrual basis). Cancelled and refunded invoices are left out.'
                : 'Invoices paid in these dates (cash basis). Refunded invoices are left out, since their tax went back too.')
            ->note('By the country on the client\'s account today and the tax rate saved on each invoice. Tax-exempt clients and reverse-charge sales show under No tax.');
    }
}
