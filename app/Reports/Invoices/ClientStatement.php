<?php

namespace App\Reports\Invoices;

use App\Billing\CreditApplier;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\InvoiceItem;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportTable;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
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
        $invoices = DB::table('invoices')->where('client_id', $client->id)->whereIn('status', $billed)
            // An unpaid add funds or mass payment invoice isn't owed: its originals are, or nothing is.
            ->whereNot(fn ($unpaidFunds) => $unpaidFunds->where('status', InvoiceStatus::Unpaid->value)
                ->whereExists(fn ($items) => $items->from('invoice_items')->whereColumn('invoice_items.invoice_id', 'invoices.id')->whereIn('invoice_items.type', InvoiceItem::FUNDS_TYPES)));
        $payments = DB::table('transactions')->where('client_id', $client->id)->where('pending', false);
        $creditNotes = DB::table('credit_notes')->where('client_id', $client->id)->whereNull('voided_at');

        $opening = (int) (clone $invoices)->where('issue_date', '<', $start->toDateString())->sum('total')
            - (int) (clone $payments)->where('created_at', '<', $start)->sum('amount')
            - (int) (clone $creditNotes)->where('created_at', '<', $start)->sum('total');

        $lines = collect();
        foreach ((clone $invoices)->whereBetween('issue_date', [$start->toDateString(), $filters->to->toDateString()])->get() as $invoice) {
            $lines->push($this->invoiceLine($invoice));
        }
        $paid = (clone $payments)->whereBetween('created_at', $filters->between())->get();
        $numbers = DB::table('invoices')->whereIn('id', $paid->pluck('invoice_id')->filter())->pluck('number', 'id');
        foreach ($paid as $payment) {
            $lines->push($this->paymentLine($payment, $numbers));
        }
        // A credit note takes its amount off what was charged; its refund shows as its own line.
        $credited = (clone $creditNotes)->whereBetween('created_at', $filters->between())->get();
        $creditedOn = DB::table('invoices')->whereIn('id', $credited->pluck('invoice_id'))->pluck('number', 'id');
        foreach ($credited as $note) {
            $lines->push($this->creditNoteLine($note, $creditedOn));
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

    /** @return array<string, mixed> */
    private function invoiceLine(object $invoice): array
    {
        return [
            'at' => "{$invoice->issue_date} 00:00:00",
            'date' => $invoice->issue_date,
            'type' => 'Invoice',
            'reference' => "#{$invoice->number}",
            'description' => 'Due '.CarbonImmutable::parse($invoice->due_date)->format('M j, Y'),
            'debit' => (int) $invoice->total,
            'credit' => 0,
            '_links' => ['reference' => route('admin.invoices.show', $invoice->id)],
        ];
    }

    /**
     * @param  Collection<int, string>  $numbers  invoice id => invoice number
     * @return array<string, mixed>
     */
    private function creditNoteLine(object $note, Collection $numbers): array
    {
        return [
            'at' => (string) $note->created_at,
            'date' => substr((string) $note->created_at, 0, 10),
            'type' => 'Credit note',
            'reference' => '#'.($numbers[$note->invoice_id] ?? $note->invoice_id),
            'description' => "{$note->number}: {$note->reason}",
            'debit' => 0,
            'credit' => (int) $note->total,
            '_links' => ['reference' => route('admin.invoices.show', $note->invoice_id)],
        ];
    }

    /**
     * @param  Collection<int, string>  $numbers  invoice id => invoice number
     * @return array<string, mixed>
     */
    private function paymentLine(object $payment, Collection $numbers): array
    {
        $amount = (int) $payment->amount;
        $fromCredit = $payment->gateway === CreditApplier::GATEWAY;

        if ($amount < 0) {
            $type = 'Refund';
        } else {
            $type = $fromCredit ? 'Credit applied' : 'Payment';
        }

        $reference = '';
        $links = [];
        if ($payment->invoice_id) {
            $reference = '#'.($numbers[$payment->invoice_id] ?? $payment->invoice_id);
            $links = ['reference' => route('admin.invoices.show', $payment->invoice_id)];
        }

        $gateway = ucfirst((string) $payment->gateway).($payment->gateway_reference ? " {$payment->gateway_reference}" : '');

        return [
            'at' => (string) $payment->created_at,
            'date' => substr((string) $payment->created_at, 0, 10),
            'type' => $type,
            'reference' => $reference,
            'description' => $fromCredit ? 'From credit balance' : $gateway,
            'debit' => max(0, -$amount),
            'credit' => max(0, $amount),
            '_links' => $links,
        ];
    }
}
