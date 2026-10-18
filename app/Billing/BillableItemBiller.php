<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Events\InvoiceIssued;
use App\Models\Activity;
use App\Models\BillableItem;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Puts billable items on invoices. The renewal invoice generator adds a
 * client's items to the invoice it makes for them; this run then invoices
 * the items still due, one invoice per client. Each item records the
 * invoice it went on, so running this again bills nothing twice.
 */
class BillableItemBiller
{
    public function __construct(private CreditApplier $credit) {}

    /** @return Collection<int, Invoice> the invoices created */
    public function run(CarbonInterface $today): Collection
    {
        $cutoff = self::cutoff($today);

        return BillableItem::dueBy($cutoff)
            ->distinct()
            ->pluck('client_id')
            ->map(fn (int $clientId) => $this->invoiceClient($clientId, $cutoff, $today))
            ->filter()
            ->each(fn (Invoice $invoice) => $this->credit->applyIfEnabled($invoice))
            ->map(fn (Invoice $invoice) => $invoice->refresh())
            ->each(fn (Invoice $invoice) => InvoiceIssued::dispatch($invoice))
            ->values();
    }

    /** Items with a due date are invoiced as far ahead as renewals are. */
    public static function cutoff(CarbonInterface $today): CarbonInterface
    {
        return $today->copy()->addDays((int) config('billing.invoice_days_before_due'));
    }

    /**
     * Adds the client's items waiting for their next invoice, and any due by
     * $cutoff, to a renewal invoice being made. Call it inside the
     * transaction that makes the invoice, before recalculating it.
     */
    public function addTo(Invoice $invoice, CarbonInterface $cutoff): void
    {
        $this->addLines($invoice, BillableItem::where('client_id', $invoice->client_id)
            ->where(fn ($items) => $items->forNextInvoice()->orWhere(fn ($due) => $due->dueBy($cutoff))));
    }

    private function invoiceClient(int $clientId, CarbonInterface $cutoff, CarbonInterface $today): ?Invoice
    {
        return DB::transaction(function () use ($clientId, $cutoff, $today) {
            $client = Client::lockForUpdate()->findOrFail($clientId);
            $items = BillableItem::where('client_id', $clientId)->dueBy($cutoff);

            if (! (clone $items)->exists()) {
                return null;
            }

            // Due on the earliest item's due date, but never before today.
            $due = (clone $items)->whereNotNull('due_date')->min('due_date');
            $invoice = Invoice::create([
                'client_id' => $client->id,
                'status' => InvoiceStatus::Unpaid,
                'currency' => $client->currency,
                'issue_date' => $today,
                'due_date' => $due !== null && $today->copy()->startOfDay()->lt($due) ? $due : $today,
            ]);

            $this->addLines($invoice, $items);
            $invoice->recalculate();
            Activity::record("Generated invoice #{$invoice->number} for billable items, ".Money::format($invoice->total, $invoice->currency), $invoice);

            return $invoice;
        });
    }

    /** One line per item, then each item notes the invoice and, if it recurs, moves on to its next due date. */
    private function addLines(Invoice $invoice, $items): void
    {
        foreach ($items->orderBy('id')->lockForUpdate()->get() as $item) {
            $invoice->items()->create([
                'billable_item_id' => $item->id,
                'type' => InvoiceItem::TYPE_BILLABLE,
                'description' => $item->lineDescription($invoice->currency),
                'amount' => $item->amount,
                'taxable' => $item->taxable,
            ]);

            $item->forceFill([
                'invoice_id' => $invoice->id,
                'invoiced_count' => $item->invoiced_count + 1,
                'due_date' => $item->isRecurring() ? $item->after($item->due_date) : $item->due_date,
            ])->save();
        }
    }
}
