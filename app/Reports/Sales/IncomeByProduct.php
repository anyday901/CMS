<?php

namespace App\Reports\Sales;

use App\Enums\InvoiceStatus;
use App\Models\InvoiceItem;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportTable;
use Illuminate\Support\Facades\DB;

/** What each product brought in on invoices paid in the dates. */
class IncomeByProduct extends Report
{
    private const OTHER_TYPE = "CASE WHEN products.id IS NULL THEN invoice_items.type ELSE '' END";

    /** How lines without a service are named. */
    private const OTHER = [
        InvoiceItem::TYPE_LATE_FEE => 'Late fees',
        InvoiceItem::TYPE_PROMOTION => 'Promotion discounts',
        InvoiceItem::TYPE_UPGRADE => 'Upgrades',
        InvoiceItem::TYPE_OVERAGE => 'Usage overages',
        InvoiceItem::TYPE_BILLABLE => 'Billable items',
    ];

    public function key(): string
    {
        return 'income-by-product';
    }

    public function title(): string
    {
        return 'Income by product';
    }

    public function group(): string
    {
        return ReportRegistry::SALES;
    }

    public function description(): string
    {
        return 'What each product brought in on invoices paid in these dates.';
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $rows = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->leftJoin('services', 'services.id', '=', 'invoice_items.service_id')
            ->leftJoin('products', 'products.id', '=', 'services.product_id')
            ->where('invoices.status', InvoiceStatus::Paid->value)
            ->where('invoices.currency', $filters->currency)
            ->whereBetween('invoices.paid_at', $filters->between())
            // Paid into credit, and counted when the credit pays an invoice.
            ->whereNotIn('invoice_items.type', InvoiceItem::FUNDS_TYPES)
            // Lines without a product are grouped by their type; a product's lines of every type count as one.
            ->groupBy('products.id', 'products.name', DB::raw(self::OTHER_TYPE))
            ->get([
                'products.id', 'products.name', DB::raw(self::OTHER_TYPE.' as type'),
                DB::raw('COUNT(*) as line_count'),
                DB::raw('COUNT(DISTINCT invoice_items.service_id) as services'),
                DB::raw('SUM(invoice_items.amount) as amount'),
            ]);

        $products = [];
        foreach ($rows as $row) {
            $products[] = [
                'product' => $row->name ?? (self::OTHER[$row->type] ?? 'Other items'),
                'services' => (int) $row->services,
                'lines' => (int) $row->line_count,
                'amount' => (int) $row->amount,
            ];
        }
        usort($products, fn (array $a, array $b) => $b['amount'] <=> $a['amount']);

        $table = new ReportTable([
            'product' => ['Product', ReportTable::TEXT],
            'services' => ['Services', ReportTable::NUMBER],
            'lines' => ['Invoice lines', ReportTable::NUMBER],
            'amount' => ['Amount', ReportTable::MONEY],
        ], currency: $filters->currency);
        $table->chart = 'amount';
        $table->rows = $products;

        return (new ReportResult)->table($table->withTotals(['product' => 'Total']))
            ->note('Invoice lines before tax on invoices paid in these dates, including setup fees and discounts on the product\'s services. Lines that aren\'t for a service are grouped by what they are.');
    }
}
