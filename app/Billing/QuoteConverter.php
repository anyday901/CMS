<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\QuoteStage;
use App\Events\InvoiceIssued;
use App\Models\Activity;
use App\Models\Invoice;
use App\Models\Quote;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Turns a quote into an unpaid invoice with the same lines and tax, and
 * marks the quote accepted. Staff can convert any quote; clients accept
 * one sent to them, before it expires, or decline it.
 */
class QuoteConverter
{
    /** Days the client has to pay the invoice a quote becomes. */
    public const DUE_DAYS = 7;

    public function __construct(private CreditApplier $credit) {}

    public function convert(Quote $quote): Invoice
    {
        $invoice = DB::transaction(function () use ($quote) {
            $quote = Quote::lockForUpdate()->findOrFail($quote->id);

            if ($quote->invoice_id !== null) {
                throw new InvalidArgumentException("Quote #{$quote->number} is already invoice #{$quote->invoice?->number}.");
            }

            if ($quote->total <= 0) {
                throw new InvalidArgumentException('The quote total must be more than zero to invoice it.');
            }

            $invoice = Invoice::create([
                'client_id' => $quote->client_id,
                'status' => InvoiceStatus::Unpaid,
                'currency' => $quote->currency,
                'issue_date' => today(),
                'due_date' => today()->addDays(self::DUE_DAYS),
                'notes' => "Quote #{$quote->number}: {$quote->subject}",
            ]);

            // The quote's tax is what the client agreed to, so the invoice keeps it rather than the rule in force today.
            $invoice->forceFill([
                'tax_name' => $quote->tax_name,
                'tax_rate' => $quote->tax_rate,
                'tax_inclusive' => $quote->tax_inclusive,
            ])->save();

            foreach ($quote->items()->orderBy('id')->get() as $item) {
                $invoice->items()->create($item->only(['description', 'amount', 'taxable']));
            }

            $quote->forceFill([
                'stage' => QuoteStage::Accepted,
                'accepted_at' => $quote->accepted_at ?? now(),
                'delivered_at' => $quote->delivered_at ?? now(),
                'invoice_id' => $invoice->id,
            ])->save();

            return $invoice->recalculate();
        });

        Activity::record("Converted quote #{$quote->number} to invoice #{$invoice->number} for ".Money::format($invoice->total, $invoice->currency), $invoice);

        $this->credit->applyIfEnabled($invoice);
        InvoiceIssued::dispatch($invoice->refresh());

        return $invoice;
    }

    /** The client accepts a quote sent to them; it becomes an invoice for them to pay. */
    public function accept(Quote $quote): Invoice
    {
        if ($problem = self::cannotAnswer($quote)) {
            throw new InvalidArgumentException($problem);
        }

        Activity::record("Client accepted quote #{$quote->number}", $quote);

        return $this->convert($quote);
    }

    /** The client turns a quote down: it is marked lost and leaves their portal. */
    public function decline(Quote $quote): void
    {
        if ($problem = self::cannotAnswer($quote)) {
            throw new InvalidArgumentException($problem);
        }

        $quote->update(['stage' => QuoteStage::Lost]);

        Activity::record("Client declined quote #{$quote->number}", $quote);
    }

    /** Why the client can't accept or decline this quote now, or null when they can. */
    public static function cannotAnswer(Quote $quote): ?string
    {
        return match (true) {
            ! $quote->awaitsClient() => __('This quote can no longer be accepted or declined.'),
            $quote->isExpired() => __('This quote expired on :date. Please contact us for a new one.', ['date' => $quote->valid_until->translatedFormat('M j, Y')]),
            default => null,
        };
    }
}
