<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Activity;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Invoice;
use App\Models\NumberSequence;
use App\Models\TaxRule;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Issues a credit note against a paid or part paid invoice and gives the
 * money back through RefundService:
 *
 * - credit: to the client's credit balance, from the invoice's payments,
 *   newest first, as far as needed.
 * - gateway or manual: as a refund of one payment the staff member picks,
 *   through its gateway or recorded as sent back outside the app.
 *
 * A credit note can't give back more than was paid on the invoice, nor more
 * of a line than is left after earlier credit notes. Its number is taken in
 * the same transaction, so a refund the gateway turns down leaves no gap.
 */
class CreditNoteIssuer
{
    public function __construct(private RefundService $refunds) {}

    /**
     * @param  array<int, int>  $lines  invoice item id => amount to credit, in cents
     */
    public function issue(Invoice $invoice, array $lines, string $reason, string $mode, ?Transaction $payment = null): CreditNote
    {
        if (! in_array($mode, CreditNote::MODES, true)) {
            throw new InvalidArgumentException("Unknown refund mode {$mode}.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Give a reason for the credit note.');
        }

        // Their money went to credit, not to a sale; refund the payment or adjust credit instead.
        if ($invoice->isAddFunds() || $invoice->isMassPayment()) {
            throw new InvalidArgumentException('Credit notes can\'t be issued against add funds or mass payment invoices.');
        }

        return DB::transaction(function () use ($invoice, $lines, $reason, $mode, $payment) {
            // The same lock as payments and refunds, so the amounts can't change underneath.
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);

            if (! self::canCredit($invoice)) {
                throw new InvalidArgumentException('Credit notes can only be issued against paid or part paid invoices.');
            }

            $items = $this->items($invoice, $lines);
            [$subtotal, $tax, $total] = $this->totals($invoice, $items);
            $most = min($invoice->amountPaid(), $invoice->total - $invoice->credited());

            if ($total > $most) {
                throw new InvalidArgumentException('That comes to '.Money::format($total, $invoice->currency).', more than is left to credit on this invoice ('.Money::format(max(0, $most), $invoice->currency).').');
            }

            $note = CreditNote::create([
                'number' => InvoiceNumbering::next(NumberSequence::CREDIT_NOTE, (string) config('billing.credit_note_number_format'), fn (string $number) => CreditNote::where('number', $number)->exists()),
                'client_id' => $invoice->client_id,
                'invoice_id' => $invoice->id,
                'currency' => $invoice->currency,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'tax_name' => $invoice->tax_name,
                'tax_rate' => $invoice->tax_rate,
                'tax_inclusive' => $invoice->tax_inclusive,
                'total' => $total,
                'reason' => trim($reason),
                'refund_mode' => $mode,
                'user_id' => Auth::guard('web')->id(),
            ]);
            $note->items()->createMany($items);

            Activity::record(
                "Issued credit note {$note->number} for ".Money::format($total, $invoice->currency)." on invoice #{$invoice->number}: {$note->reason}",
                $note,
                ['refund_mode' => $mode, 'invoice_id' => $invoice->id],
            );

            // Last, so a gateway refund is only sent once everything else is in place.
            $this->giveBack($note, $invoice, $mode, $payment);

            return $note;
        });
    }

    /** Paid invoices, and unpaid ones with something paid on them. */
    public static function canCredit(Invoice $invoice): bool
    {
        return match ($invoice->status) {
            InvoiceStatus::Paid => true,
            InvoiceStatus::Unpaid => $invoice->amountPaid() > 0,
            default => false,
        };
    }

    /**
     * What is left to credit on each line of an invoice, after earlier credit notes.
     *
     * @return array<int, int> invoice item id => cents
     */
    public static function creditableLines(Invoice $invoice): array
    {
        $credited = CreditNoteItem::whereIn('invoice_item_id', $invoice->items()->select('id'))
            ->groupBy('invoice_item_id')
            ->selectRaw('invoice_item_id, sum(amount) as credited')
            ->pluck('credited', 'invoice_item_id');

        return $invoice->items()->where('amount', '>', 0)->get()
            ->mapWithKeys(fn ($item) => [$item->id => max(0, $item->amount - (int) ($credited[$item->id] ?? 0))])
            ->all();
    }

    /**
     * The credit note's lines, checked against what's left on each invoice line.
     *
     * @param  array<int, int>  $lines
     * @return list<array{invoice_item_id: int, description: string, amount: int, taxable: bool}>
     */
    private function items(Invoice $invoice, array $lines): array
    {
        $left = self::creditableLines($invoice);
        $invoiceItems = $invoice->items()->get()->keyBy('id');
        $items = [];

        foreach ($lines as $id => $amount) {
            if ($amount === 0) {
                continue;
            }

            $item = $invoiceItems->get($id);

            if ($item === null || ! array_key_exists($id, $left)) {
                throw new InvalidArgumentException('Only the invoice\'s own charges can be credited.');
            }

            if ($amount < 0 || $amount > $left[$id]) {
                throw new InvalidArgumentException("\"{$item->description}\" has ".Money::format($left[$id], $invoice->currency).' left to credit.');
            }

            $items[] = ['invoice_item_id' => $item->id, 'description' => $item->description, 'amount' => $amount, 'taxable' => (bool) $item->taxable];
        }

        if ($items === []) {
            throw new InvalidArgumentException('Enter an amount to credit on at least one line.');
        }

        return $items;
    }

    /**
     * Subtotal, tax and total, worked out the same way as the invoice's.
     *
     * @param  list<array{amount: int, taxable: bool}>  $items
     * @return array{0: int, 1: int, 2: int}
     */
    private function totals(Invoice $invoice, array $items): array
    {
        $subtotal = array_sum(array_column($items, 'amount'));
        $taxable = array_sum(array_column(array_filter($items, fn ($item) => $item['taxable']), 'amount'));

        if ($invoice->tax_inclusive) {
            return [$subtotal, TaxRule::taxIncludedIn($taxable, $invoice->tax_rate), $subtotal];
        }

        $tax = TaxRule::taxOn($taxable, $invoice->tax_rate);

        return [$subtotal, $tax, $subtotal + $tax];
    }

    private function giveBack(CreditNote $note, Invoice $invoice, string $mode, ?Transaction $payment): void
    {
        if ($mode !== 'credit') {
            if ($payment === null || $payment->invoice_id !== $invoice->id || $payment->isRefund()) {
                throw new InvalidArgumentException('Choose one of this invoice\'s payments to refund.');
            }

            if ($note->total > $payment->refundable()) {
                throw new InvalidArgumentException('That payment has '.Money::format($payment->refundable(), $invoice->currency).' left to refund. Choose another payment, or add the money to account credit.');
            }

            $this->link($note, $this->refunds->refund($payment, $note->total, $mode));

            return;
        }

        $left = $note->total;

        foreach ($invoice->transactions()->where('amount', '>', 0)->latest('id')->get() as $paid) {
            $amount = min($left, $paid->refundable());

            if ($amount > 0) {
                $this->link($note, $this->refunds->refund($paid, $amount, 'credit'));
                $left -= $amount;
            }

            if ($left === 0) {
                return;
            }
        }

        throw new InvalidArgumentException('The invoice\'s payments don\'t have enough left to refund.');
    }

    private function link(CreditNote $note, Transaction $refund): void
    {
        $refund->forceFill(['credit_note_id' => $note->id])->save();
    }
}
