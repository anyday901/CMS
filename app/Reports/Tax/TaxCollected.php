<?php

namespace App\Reports\Tax;

use App\Enums\InvoiceStatus;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportTable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Tax charged on invoices by country and rate, for sales tax and VAT returns. */
class TaxCollected extends Report
{
    /** The European Union's member states, for VAT returns. */
    public const EU = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'];

    /** Each row is one combination of these: the client's country and the tax saved on the invoice. */
    private const RATE_COLUMNS = ['clients.country', 'invoices.tax_name', 'invoices.tax_rate', 'invoices.tax2_name', 'invoices.tax2_rate', 'invoices.reverse_charge'];

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
        $issued = $filters->option('basis') === 'issued';
        // Refunded invoices stay in the period they were sold in; the refund takes the tax back in its own period.
        $statuses = $issued ? [InvoiceStatus::Unpaid, InvoiceStatus::Paid, InvoiceStatus::Refunded] : [InvoiceStatus::Paid, InvoiceStatus::Refunded];

        $query = $this->invoices($filters)
            ->whereIn('invoices.status', array_map(fn (InvoiceStatus $status) => $status->value, $statuses))
            ->when($issued,
                fn ($query) => $query->whereBetween('invoices.issue_date', [$filters->from->toDateString(), $filters->to->toDateString()]),
                fn ($query) => $query->whereBetween('invoices.paid_at', $filters->between()));

        $rows = [];
        foreach ($query->groupBy(...self::RATE_COLUMNS)
            ->get([
                ...self::RATE_COLUMNS,
                DB::raw('COUNT(*) as invoices'),
                DB::raw('SUM(invoices.total - invoices.tax) as sales'),
                DB::raw('SUM(invoices.tax) as tax'),
                DB::raw('SUM(invoices.tax2) as tax2'),
            ]) as $row) {
            $this->addTo($rows, $row, (int) $row->invoices, (int) $row->sales, (int) $row->tax, (int) $row->tax2);
        }

        foreach ($this->refunds($filters) as $refund) {
            // The refunded share of the invoice's tax, in proportion to the amount refunded.
            $share = fn (int $tax) => (int) $refund->total > 0 ? (int) round(-(int) $refund->amount * $tax / (int) $refund->total) : 0;
            $tax = $share((int) $refund->tax);
            $this->addTo($rows, $refund, 0, (int) $refund->amount + $tax, -$tax, -$share((int) $refund->tax2));
        }

        ksort($rows);

        // Level 2 columns only appear once an invoice in the period has level 2 tax.
        $levels = collect($rows)->contains(fn (array $row) => $row['rate2'] > 0);
        $table = new ReportTable([
            'country' => ['Country', ReportTable::TEXT],
            'tax_name' => ['Tax', ReportTable::TEXT],
            'rate' => ['Rate', ReportTable::PERCENT],
            ...($levels ? ['tax2_name' => ['Level 2 tax', ReportTable::TEXT], 'rate2' => ['Level 2 rate', ReportTable::PERCENT]] : []),
            'invoices' => ['Invoices', ReportTable::NUMBER],
            'sales' => ['Sales before tax', ReportTable::MONEY],
            ...($levels ? ['tax1' => ['Level 1', ReportTable::MONEY], 'tax2' => ['Level 2', ReportTable::MONEY]] : []),
            'tax' => ['Tax', ReportTable::MONEY],
        ], currency: $filters->currency);
        $table->chart = 'tax';

        $table->rows = array_values($rows);

        return (new ReportResult)->table($table->withTotals(['country' => 'Total']))
            ->note($issued
                ? 'Invoices issued in these dates, paid or not (accrual basis). Cancelled invoices are left out.'
                : 'Invoices paid in these dates (cash basis).')
            ->note('Refunds made in these dates take their share of the invoice\'s tax back, in the period they were made, so earlier periods don\'t change.')
            ->note('By the country on the client\'s account today and the tax rates saved on each invoice. Tax-exempt clients show under No tax, and EU sales with no VAT charged under Reverse charge.');
    }

    private function invoices(ReportFilters $filters): Builder
    {
        return DB::table('invoices')
            ->join('clients', 'clients.id', '=', 'invoices.client_id')
            ->where('invoices.currency', $filters->currency)
            ->when($filters->option('region') === 'eu', fn ($query) => $query->whereIn('clients.country', self::EU));
    }

    /** Refunds made in these dates, with the invoice they came off. */
    private function refunds(ReportFilters $filters): Collection
    {
        return $this->invoices($filters)
            ->join('transactions', 'transactions.invoice_id', '=', 'invoices.id')
            ->where('transactions.amount', '<', 0)
            ->where('transactions.pending', false)
            ->whereBetween('transactions.created_at', $filters->between())
            ->get([...self::RATE_COLUMNS, 'invoices.total', 'invoices.tax', 'invoices.tax2', 'transactions.amount']);
    }

    /** @param  array<string, array<string, mixed>>  $rows */
    private function addTo(array &$rows, object $row, int $invoices, int $sales, int $tax, int $tax2): void
    {
        $rate = (int) $row->tax_rate;
        $rate2 = (int) $row->tax2_rate;
        $taxName = $row->tax_name ?: 'Tax';
        $tax2Name = '';
        if ($rate2 > 0) {
            $tax2Name = $row->tax2_name ?: 'Tax';
        }
        $country = $row->country ?: 'Unknown';
        $key = sprintf('%s|%06d|%s|%06d|%s|%d', $country, 999999 - $rate, $taxName, 999999 - $rate2, $tax2Name, $row->reverse_charge);

        $rows[$key] ??= [
            'country' => $country,
            'tax_name' => match (true) {
                $rate > 0 => $taxName,
                (bool) $row->reverse_charge => 'Reverse charge',
                $rate2 > 0 => '',
                default => 'No tax',
            },
            'rate' => $rate / 100,
            'tax2_name' => $tax2Name,
            'rate2' => $rate2 / 100,
            'invoices' => 0,
            'sales' => 0,
            'tax1' => 0,
            'tax2' => 0,
            'tax' => 0,
        ];
        $rows[$key]['invoices'] += $invoices;
        $rows[$key]['sales'] += $sales;
        $rows[$key]['tax1'] += $tax - $tax2;
        $rows[$key]['tax2'] += $tax2;
        $rows[$key]['tax'] += $tax;
    }
}
