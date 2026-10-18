<?php

namespace App\Reports\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\InvoiceItem;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportTable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** What clients owe today, by how long it has been overdue. */
class InvoiceAging extends Report
{
    /** Column => the most days overdue it holds. */
    private const BUCKETS = ['current' => 0, 'days_30' => 30, 'days_60' => 60, 'days_90' => 90, 'days_older' => PHP_INT_MAX];

    public function key(): string
    {
        return 'invoice-aging';
    }

    public function title(): string
    {
        return 'Invoice aging';
    }

    public function group(): string
    {
        return ReportRegistry::INVOICES;
    }

    public function description(): string
    {
        return 'What each client owes today on unpaid invoices, by how many days it is overdue.';
    }

    public function filters(): array
    {
        return [self::CURRENCY];
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $today = CarbonImmutable::today();
        $paid = DB::table('transactions')->select('invoice_id', DB::raw('SUM(amount) as paid'))->whereNotNull('invoice_id')->groupBy('invoice_id');
        // Credit notes take their amount off what's owed, as their refunds take it off what's paid.
        $credited = DB::table('credit_notes')->select('invoice_id', DB::raw('SUM(total) as credited'))->whereNull('voided_at')->groupBy('invoice_id');

        $invoices = DB::table('invoices')
            ->join('clients', 'clients.id', '=', 'invoices.client_id')
            ->leftJoinSub($paid, 'paid', 'paid.invoice_id', '=', 'invoices.id')
            ->leftJoinSub($credited, 'credited', 'credited.invoice_id', '=', 'invoices.id')
            ->where('invoices.status', InvoiceStatus::Unpaid->value)
            ->where('invoices.currency', $filters->currency)
            // Add funds and mass payment invoices aren't owed: their originals are, or nothing is.
            ->whereNotExists(fn ($items) => $items->from('invoice_items')->whereColumn('invoice_items.invoice_id', 'invoices.id')->whereIn('invoice_items.type', InvoiceItem::FUNDS_TYPES))
            ->orderBy('invoices.id')
            ->get(['invoices.client_id', 'invoices.due_date', 'invoices.total', 'paid.paid', 'credited.credited', 'clients.first_name', 'clients.last_name', 'clients.company']);

        $clients = [];
        foreach ($invoices as $invoice) {
            $balance = (int) $invoice->total - (int) $invoice->credited - (int) $invoice->paid;
            if ($balance <= 0) {
                continue;
            }

            $overdue = (int) CarbonImmutable::parse($invoice->due_date)->diffInDays($today, false);
            $bucket = collect(self::BUCKETS)->search(fn (int $max) => $overdue <= $max);
            $name = trim("{$invoice->first_name} {$invoice->last_name}");

            $clients[$invoice->client_id] ??= ['client' => $invoice->company ? "{$name} ({$invoice->company})" : $name, 'invoices' => 0] + array_fill_keys(array_keys(self::BUCKETS), 0) + ['total' => 0, '_links' => ['client' => route('admin.clients.show', $invoice->client_id)]];
            $clients[$invoice->client_id]['invoices']++;
            $clients[$invoice->client_id][$bucket] += $balance;
            $clients[$invoice->client_id]['total'] += $balance;
        }
        uasort($clients, fn (array $a, array $b) => $b['total'] <=> $a['total']);

        $table = new ReportTable([
            'client' => ['Client', ReportTable::TEXT],
            'invoices' => ['Unpaid invoices', ReportTable::NUMBER],
            'current' => ['Not yet due', ReportTable::MONEY],
            'days_30' => ['1–30 days', ReportTable::MONEY],
            'days_60' => ['31–60 days', ReportTable::MONEY],
            'days_90' => ['61–90 days', ReportTable::MONEY],
            'days_older' => ['Over 90 days', ReportTable::MONEY],
            'total' => ['Owed', ReportTable::MONEY],
        ], currency: $filters->currency);
        $table->rows = array_values($clients);
        $table->empty = 'No unpaid invoices.';

        return (new ReportResult)->table($table->withTotals(['client' => 'Total']))
            ->note('As of today: what is left to pay on each unpaid invoice, by the days since its due date. Drafts aren\'t included.');
    }
}
