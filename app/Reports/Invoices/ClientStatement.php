<?php

namespace App\Reports\Invoices;

use App\Billing\CreditApplier;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportTable;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** One client's invoices and payments in date order, with the balance after each. */
class ClientStatement extends Report
{
    /** Invoices that were billed: not drafts, and not cancelled ones. */
    private const BILLED = [InvoiceStatus::Unpaid, InvoiceStatus::Paid, InvoiceStatus::Refunded];

    public function key(): string
    {
        return 'client-statement';
    }

    public function title(): string
    {
        return 'Client statement';
    }

    public function group(): string
    {
        return ReportRegistry::INVOICES;
    }

    public function description(): string
    {
        return 'One client\'s invoices, payments and refunds in date order, with what they owed after each.';
    }

    public function filters(): array
    {
        return [self::DATES, self::CLIENT];
    }

    public function defaultRange(CarbonImmutable $today): array
    {
        return [$today->startOfYear(), $today];
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $client = $filters->client;

        if ($client === null) {
            return (new ReportResult)->note('Enter a client\'s number or email address to see their statement.');
        }

        $start = $filters->from->startOfDay();
        $billed = array_map(fn (InvoiceStatus $status) => $status->value, self::BILLED);
        $invoices = DB::table('invoices')->where('client_id', $client->id)->whereIn('status', $billed);
        $payments = DB::table('transactions')->where('client_id', $client->id)->where('pending', false);

        $opening = (int) (clone $invoices)->where('issue_date', '<', $start->toDateString())->sum('total')
            - (int) (clone $payments)->where('created_at', '<', $start)->sum('amount');

        $lines = collect();
        foreach ((clone $invoices)->whereBetween('issue_date', [$start->toDateString(), $filters->to->toDateString()])->get() as $invoice) {
            $lines->push(['at' => "{$invoice->issue_date} 00:00:00", 'date' => $invoice->issue_date, 'type' => 'Invoice', 'reference' => "#{$invoice->number}", 'description' => 'Due '.CarbonImmutable::parse($invoice->due_date)->format('M j, Y'), 'debit' => (int) $invoice->total, 'credit' => 0, '_links' => ['reference' => route('admin.invoices.show', $invoice->id)]]);
        }
        $paid = (clone $payments)->whereBetween('created_at', $filters->between())->get();
        $numbers = DB::table('invoices')->whereIn('id', $paid->pluck('invoice_id')->filter())->pluck('number', 'id');
        foreach ($paid as $payment) {
            $amount = (int) $payment->amount;
            $lines->push([
                'at' => (string) $payment->created_at,
                'date' => substr((string) $payment->created_at, 0, 10),
                'type' => $amount < 0 ? 'Refund' : ($payment->gateway === CreditApplier::GATEWAY ? 'Credit applied' : 'Payment'),
                'reference' => $payment->invoice_id ? '#'.($numbers[$payment->invoice_id] ?? $payment->invoice_id) : '',
                'description' => $payment->gateway === CreditApplier::GATEWAY ? 'From credit balance' : ucfirst((string) $payment->gateway).($payment->gateway_reference ? " {$payment->gateway_reference}" : ''),
                'debit' => $amount < 0 ? -$amount : 0,
                'credit' => max(0, $amount),
                '_links' => $payment->invoice_id ? ['reference' => route('admin.invoices.show', $payment->invoice_id)] : [],
            ]);
        }

        $table = new ReportTable([
            'date' => ['Date', ReportTable::DATE],
            'type' => ['Type', ReportTable::TEXT],
            'reference' => ['Invoice', ReportTable::TEXT],
            'description' => ['Details', ReportTable::TEXT],
            'debit' => ['Charged', ReportTable::MONEY],
            'credit' => ['Paid', ReportTable::MONEY],
            'balance' => ['Balance', ReportTable::MONEY],
        ], $client->fullName(), $client->currency);
        $table->empty = 'No invoices or payments in these dates.';

        $balance = $opening;
        foreach ($lines->sortBy([['at', 'asc'], ['debit', 'desc']]) as $line) {
            $balance += $line['debit'] - $line['credit'];
            unset($line['at']);
            $table->add($line + ['balance' => $balance]);
        }
        $table->withTotals(['date' => null, 'type' => 'Total', 'balance' => $balance]);

        return (new ReportResult)->table($table)
            ->note('Balance brought forward from before '.$start->format('M j, Y').': '.Money::format($opening, $client->currency).'. Balance is what the client owed after each line; below zero, they had paid ahead.')
            ->note('Drafts and cancelled invoices aren\'t included. Payments from the credit balance show as Credit applied.');
    }

    public static function label(Client $client): string
    {
        return "#{$client->id} {$client->fullName()} ({$client->email})";
    }
}
