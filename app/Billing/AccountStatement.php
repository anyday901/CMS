<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\CreditEntry;
use App\Models\CreditNote;
use App\Models\InvoiceItem;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A client's account statement for a date range: what they owed before it,
 * each invoice, payment and refund in it with the balance after each line,
 * and the changes to their credit balance. The admin's client statement
 * report and the portal's statement page both read it.
 */
final class AccountStatement
{
    public const INVOICE = 'invoice';

    public const PAYMENT = 'payment';

    public const REFUND = 'refund';

    public const CREDIT_APPLIED = 'credit_applied';

    public const CREDIT_NOTE = 'credit_note';

    /** Invoices that were billed: not drafts, and not cancelled ones. */
    private const BILLED = [InvoiceStatus::Unpaid, InvoiceStatus::Paid, InvoiceStatus::Refunded];

    /**
     * @param  list<array{type: string, at: string, date: string, invoice_id: ?int, invoice_number: ?string, due_date: ?string, method: ?string, gateway: ?string, reference: ?string, debit: int, credit: int, balance: int}>  $lines
     * @param  list<array{at: string, date: string, description: string, invoice_id: ?int, amount: int, balance: int}>  $creditEntries
     */
    private function __construct(
        public readonly Client $client,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly int $opening,
        public readonly array $lines,
        public readonly int $closing,
        public readonly int $creditOpening,
        public readonly array $creditEntries,
        public readonly int $creditClosing,
    ) {}

    /** The statement from the start of $from to the end of $to, in the client's currency. */
    public static function for(Client $client, CarbonImmutable $from, CarbonImmutable $to): self
    {
        $start = $from->startOfDay();
        $end = $to->endOfDay();
        $billed = array_map(fn (InvoiceStatus $status) => $status->value, self::BILLED);
        $invoices = DB::table('invoices')->where('client_id', $client->id)->whereIn('status', $billed)
            // An unpaid add funds or mass payment invoice isn't owed: its originals are, or nothing is.
            ->whereNot(fn ($unpaidFunds) => $unpaidFunds->where('status', InvoiceStatus::Unpaid->value)
                ->whereExists(fn ($items) => $items->from('invoice_items')->whereColumn('invoice_items.invoice_id', 'invoices.id')->whereIn('invoice_items.type', InvoiceItem::FUNDS_TYPES)));
        $payments = Transaction::query()->where('client_id', $client->id)->where('pending', false);
        $creditNotes = CreditNote::query()->where('client_id', $client->id)->whereNull('voided_at');

        $opening = (int) (clone $invoices)->where('issue_date', '<', $start->toDateString())->sum('total')
            - (int) (clone $payments)->where('created_at', '<', $start)->sum('amount')
            - (int) (clone $creditNotes)->where('created_at', '<', $start)->sum('total');

        $lines = collect();
        // Before the day after the range, not "between": SQLite keeps dates as "2026-10-15 00:00:00", which sorts after "2026-10-15".
        $inRange = (clone $invoices)->where('issue_date', '>=', $start->toDateString())->where('issue_date', '<', $end->addDay()->toDateString());
        foreach ($inRange->get() as $invoice) {
            $lines->push(self::invoiceLine($invoice));
        }
        $paid = (clone $payments)->whereBetween('created_at', [$start, $end])->get();
        $numbers = DB::table('invoices')->whereIn('id', $paid->pluck('invoice_id')->filter())->pluck('number', 'id');
        foreach ($paid as $payment) {
            $lines->push(self::paymentLine($payment, $payment->invoice_id ? ($numbers[$payment->invoice_id] ?? (string) $payment->invoice_id) : null));
        }
        // A credit note takes its amount off what was charged; its refund shows as its own line.
        foreach ((clone $creditNotes)->with('invoice')->whereBetween('created_at', [$start, $end])->get() as $note) {
            $lines->push(self::creditNoteLine($note));
        }

        $balance = $opening;
        $rows = [];
        foreach ($lines->sortBy([['at', 'asc'], ['debit', 'desc']]) as $line) {
            $balance += $line['debit'] - $line['credit'];
            $rows[] = $line + ['balance' => $balance];
        }

        [$creditOpening, $creditEntries, $creditClosing] = self::credit($client, $start, $end);

        return new self($client, $start, $to->startOfDay(), $opening, $rows, $balance, $creditOpening, $creditEntries, $creditClosing);
    }

    /** What was charged in the range: the invoices' totals and refunds paid back. */
    public function charged(): int
    {
        return array_sum(array_column($this->lines, 'debit'));
    }

    /** What was paid in the range, including credit applied to invoices. */
    public function paid(): int
    {
        return array_sum(array_column($this->lines, 'credit'));
    }

    /**
     * A line's type as the client reads it, in their language.
     *
     * @param  array<string, mixed>  $line
     */
    public static function typeLabel(array $line): string
    {
        return match ($line['type']) {
            self::INVOICE => __('Invoice'),
            self::REFUND => __('Refund'),
            self::CREDIT_APPLIED => __('Credit applied'),
            self::CREDIT_NOTE => __('Credit note'),
            default => __('Payment'),
        };
    }

    /**
     * A line's details as the client reads them: when an invoice is due, or how a payment was made.
     *
     * @param  array<string, mixed>  $line
     */
    public static function details(array $line): string
    {
        return match ($line['type']) {
            self::INVOICE => __('Due :date', ['date' => CarbonImmutable::parse($line['due_date'])->translatedFormat('M j, Y')]),
            self::CREDIT_APPLIED => __('From your credit balance'),
            self::CREDIT_NOTE => "{$line['reference']}: {$line['method']}",
            default => trim($line['method'].' '.$line['reference']),
        };
    }

    /**
     * The credit balance before the range, its changes in the range, and the balance at its end.
     *
     * @return array{0: int, 1: list<array{at: string, date: string, description: string, invoice_id: ?int, amount: int, balance: int}>, 2: int}
     */
    private static function credit(Client $client, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $before = $client->creditEntries()->where('created_at', '<', $start)->latest('created_at')->latest('id')->first();
        $opening = (int) $before?->balance_after;

        $entries = $client->creditEntries()->whereBetween('created_at', [$start, $end])->oldest('created_at')->oldest('id')->get()
            ->map(fn (CreditEntry $entry) => [
                'at' => (string) $entry->created_at,
                'date' => $entry->created_at->toDateString(),
                'description' => (string) $entry->description,
                'invoice_id' => $entry->invoice_id,
                'amount' => $entry->amount,
                'balance' => $entry->balance_after,
            ])->all();

        return [$opening, $entries, $entries === [] ? $opening : $entries[array_key_last($entries)]['balance']];
    }

    /** @return array<string, mixed> */
    private static function invoiceLine(object $invoice): array
    {
        $date = substr((string) $invoice->issue_date, 0, 10);

        return [
            'type' => self::INVOICE,
            'at' => "{$date} 00:00:00",
            'date' => $date,
            'invoice_id' => (int) $invoice->id,
            'invoice_number' => (string) $invoice->number,
            'due_date' => substr((string) $invoice->due_date, 0, 10),
            'method' => null,
            'gateway' => null,
            'reference' => null,
            'debit' => (int) $invoice->total,
            'credit' => 0,
        ];
    }

    /**
     * A credit note's line: its number as the reference and its reason as the method.
     *
     * @return array<string, mixed>
     */
    private static function creditNoteLine(CreditNote $note): array
    {
        return [
            'type' => self::CREDIT_NOTE,
            'at' => (string) $note->created_at,
            'date' => $note->created_at->toDateString(),
            'invoice_id' => $note->invoice_id,
            'invoice_number' => $note->invoice?->number,
            'due_date' => null,
            'method' => $note->reason,
            'gateway' => null,
            'reference' => $note->number,
            'debit' => 0,
            'credit' => $note->total,
        ];
    }

    /** @return array<string, mixed> */
    private static function paymentLine(Transaction $payment, ?string $invoiceNumber): array
    {
        $amount = $payment->amount;

        $type = match (true) {
            $amount < 0 => self::REFUND,
            $payment->gateway === CreditApplier::GATEWAY => self::CREDIT_APPLIED,
            default => self::PAYMENT,
        };

        return [
            'type' => $type,
            'at' => (string) $payment->created_at,
            'date' => $payment->created_at->toDateString(),
            'invoice_id' => $payment->invoice_id,
            'invoice_number' => $invoiceNumber,
            'due_date' => null,
            'method' => $payment->methodLabel(),
            'gateway' => (string) $payment->gateway,
            'reference' => $payment->gateway_reference,
            'debit' => max(0, -$amount),
            'credit' => max(0, $amount),
        ];
    }
}
