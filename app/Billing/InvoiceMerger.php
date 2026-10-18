<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Activity;
use App\Models\BillableItem;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\ServiceUpgrade;
use App\Models\ServiceUsage;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Merges a client's unpaid invoices into one, as WHMCS's "Merge Invoices"
 * does. The lines of the others are copied onto the one kept, and the others
 * are cancelled with a note saying where their lines went (keeping their own
 * lines, so their history still reads right). Anything already paid on them
 * moves across through the client's credit, so the gateway payments stay on
 * the invoices they were made on.
 */
class InvoiceMerger
{
    /** Line fields copied across, everything but the invoice it is on. */
    private const ITEM_FIELDS = ['service_id', 'domain_id', 'paid_invoice_id', 'billable_item_id', 'type', 'description', 'amount', 'taxable', 'period_start', 'period_end'];

    public function __construct(
        private RefundService $refunds,
        private CreditApplier $credit,
        private MassPayment $massPayments,
    ) {}

    /**
     * Returns the invoice kept: the oldest one with a payment on it, or else
     * the oldest. It takes the earliest due date of those merged.
     *
     * @param  list<int>  $ids
     */
    public function merge(array $ids): Invoice
    {
        [$target, $merged, $moved] = DB::transaction(function () use ($ids) {
            // In id order, as everywhere else, so two merges can't deadlock.
            $invoices = Invoice::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $this->check($invoices, $ids);

            $target = $invoices->first(fn (Invoice $invoice) => $invoice->amountPaid() !== 0) ?? $invoices->first();
            $others = $invoices->reject(fn (Invoice $invoice) => $invoice->is($target));
            $this->massPayments->cancelCovering($invoices->pluck('id')->all());
            $moved = 0;

            foreach ($others as $invoice) {
                foreach ($invoice->items()->orderBy('id')->get() as $item) {
                    $target->items()->create($item->only(self::ITEM_FIELDS));
                }

                $moved += $this->creditPayments($invoice);
                $this->repoint($invoice, $target);

                $invoice->update([
                    'status' => InvoiceStatus::Cancelled,
                    'notes' => trim($invoice->notes."\n\nMerged into invoice #{$target->number}."),
                ]);
                Activity::record("Merged invoice #{$invoice->number} into invoice #{$target->number}", $invoice);
            }

            $target->update(['due_date' => $invoices->min('due_date')]);
            $target->recalculate();

            return [$target, $others, $moved];
        });

        // What was paid on the others, now in credit, goes on the merged invoice.
        if ($moved > 0) {
            $this->credit->apply($target, $moved);
        }

        Activity::record("Merged invoices #{$merged->pluck('number')->implode(', #')} into invoice #{$target->number}, total now "
            .Money::format($target->total, $target->currency), $target);

        return $target->refresh();
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @param  list<int>  $ids
     *
     * @throws InvalidArgumentException when the invoices can't be merged
     */
    private function check(Collection $invoices, array $ids): void
    {
        $first = $invoices->first();

        $error = match (true) {
            $invoices->count() < 2 || $invoices->count() !== count(array_unique($ids)) => 'Choose at least two invoices to merge.',
            $invoices->contains(fn (Invoice $invoice) => $invoice->status !== InvoiceStatus::Unpaid) => 'Only unpaid invoices can be merged.',
            $invoices->pluck('client_id')->unique()->count() > 1 => 'Only invoices of the same client can be merged.',
            $invoices->pluck('currency')->unique()->count() > 1 => 'Only invoices in the same currency can be merged.',
            // One invoice has one tax rate, so merging different ones would change the tax owed.
            $invoices->contains(fn (Invoice $invoice) => self::taxSnapshot($invoice) !== self::taxSnapshot($first)) => 'These invoices have different tax rates, so they can\'t be merged.',
            $invoices->contains(fn (Invoice $invoice) => $invoice->isAddFunds() || $invoice->isMassPayment()) => 'Add funds and mass payment invoices can\'t be merged.',
            // Its lines would move without what was credited on them.
            $invoices->contains(fn (Invoice $invoice) => $invoice->creditNotes()->exists()) => 'Invoices with a credit note can\'t be merged.',
            default => null,
        };

        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }
    }

    /** @return list<mixed> */
    private static function taxSnapshot(Invoice $invoice): array
    {
        return [$invoice->tax_name, (int) $invoice->tax_rate, (bool) $invoice->tax_inclusive, $invoice->tax2_name, (int) $invoice->tax2_rate, (bool) $invoice->tax2_compound, (bool) $invoice->reverse_charge];
    }

    /**
     * Refunds what was paid on an invoice being merged to the client's
     * credit, recorded against the payments it came from. Returns the total.
     */
    private function creditPayments(Invoice $invoice): int
    {
        $moved = 0;

        foreach ($invoice->transactions()->whereNull('refund_of_id')->where('amount', '>', 0)->orderBy('id')->get() as $payment) {
            $amount = $payment->refundable();

            if ($amount > 0) {
                $this->refunds->refund($payment, $amount, 'credit');
                $moved += $amount;
            }
        }

        return $moved;
    }

    /** Points what waits on the merged invoice at the one kept, so it acts once that is paid. */
    private function repoint(Invoice $from, Invoice $to): void
    {
        ServiceUpgrade::where('invoice_id', $from->id)->where('status', ServiceUpgrade::PENDING)->update(['invoice_id' => $to->id]);
        Order::where('invoice_id', $from->id)->update(['invoice_id' => $to->id]);
        ServiceUsage::where('invoice_id', $from->id)->update(['invoice_id' => $to->id]);
        BillableItem::where('invoice_id', $from->id)->update(['invoice_id' => $to->id]);
    }
}
