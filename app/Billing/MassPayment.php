<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Pays several unpaid invoices in one go, as WHMCS's mass payment does: one
 * new invoice with a line per invoice it pays. Its payment goes into the
 * client's credit (see PaymentRecorder) and is then applied to each of those
 * invoices, so a payment is never lost if one of them changed in between.
 * The originals stay payable on their own until then.
 */
class MassPayment
{
    /** An unpaid mass payment invoice is cancelled this many days after it was due. */
    public const EXPIRES_AFTER_DAYS = 14;

    public function __construct(private CreditApplier $credit, private InvoiceCanceller $canceller) {}

    public static function enabled(): bool
    {
        return (bool) config('billing.mass_pay');
    }

    /**
     * The client's invoices that can go on a mass payment: unpaid, in their
     * currency, with something left to pay, and not add funds or mass
     * payment invoices themselves.
     *
     * @return Collection<int, Invoice>
     */
    public function payable(Client $client): Collection
    {
        return $client->invoices()
            ->where('status', InvoiceStatus::Unpaid)
            ->where('currency', $client->currency)
            ->withoutFunds()
            ->orderBy('due_date')->orderBy('id')
            ->get()
            ->filter(fn (Invoice $invoice) => $invoice->balance() > 0)
            ->values();
    }

    /**
     * Creates a mass payment invoice, due today, for what is left to pay on
     * each of the client's invoices in $ids. An earlier unpaid mass payment
     * of any of them is cancelled, so only one asks for the money.
     *
     * @param  list<int>  $ids
     *
     * @throws InvalidArgumentException with a message for the client
     */
    public function create(Client $client, array $ids): Invoice
    {
        if (! self::enabled()) {
            throw new InvalidArgumentException(__('Paying several invoices at once is not available.'));
        }

        $invoice = DB::transaction(function () use ($client, $ids) {
            // Locked in id order, as everywhere else, and before any payment can land on them.
            $invoices = $client->invoices()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $payable = $this->payable($client)->pluck('id');

            if ($invoices->count() < 2 || $invoices->count() !== count(array_unique($ids))) {
                throw new InvalidArgumentException(__('Choose at least two unpaid invoices to pay together.'));
            }

            if ($invoices->contains(fn (Invoice $invoice) => ! $payable->contains($invoice->id))) {
                throw new InvalidArgumentException(__('Some of those invoices can no longer be paid together. Please choose again.'));
            }

            $this->cancelCovering($invoices->pluck('id')->all());

            $mass = Invoice::create([
                'client_id' => $client->id,
                'status' => InvoiceStatus::Unpaid,
                'currency' => $client->currency,
                'issue_date' => today(),
                'due_date' => today(),
            ]);

            foreach ($invoices->sortBy('due_date') as $invoice) {
                $mass->items()->create([
                    'type' => InvoiceItem::TYPE_MASS_PAY,
                    'paid_invoice_id' => $invoice->id,
                    'description' => "Invoice #{$invoice->number}",
                    'amount' => $invoice->balance(),
                    'taxable' => false,
                ]);
            }

            return $mass->recalculate();
        });

        Activity::record("Created mass payment invoice #{$invoice->number} for ".Money::format($invoice->total, $invoice->currency).' paying invoices #'
            .$invoice->items()->with('paidInvoice')->get()->map(fn (InvoiceItem $item) => $item->paidInvoice->number)->implode(', #'), $invoice);

        return $invoice;
    }

    /**
     * Uses a paid mass payment's credit on each invoice it covers. One paid
     * or cancelled some other way in the meantime is skipped, and what was
     * meant for it stays in the client's credit.
     */
    public function paid(Invoice $invoice): void
    {
        $items = $invoice->items()->where('type', InvoiceItem::TYPE_MASS_PAY)->whereNotNull('paid_invoice_id')->with('paidInvoice')->orderBy('id')->get();

        foreach ($items as $item) {
            if ($item->amount > 0 && $item->paidInvoice !== null) {
                $this->credit->apply($item->paidInvoice, $item->amount);
            }
        }
    }

    /** The unpaid mass payment invoice that covers $invoice, if there is one. */
    public function openFor(Invoice $invoice): ?Invoice
    {
        return $this->open()
            ->whereHas('items', fn ($items) => $items->where('type', InvoiceItem::TYPE_MASS_PAY)->where('paid_invoice_id', $invoice->id))
            ->latest('id')
            ->first();
    }

    /**
     * Cancels unpaid mass payments nothing has been paid on that are past
     * EXPIRES_AFTER_DAYS, or cover an invoice that was paid or cancelled on
     * its own, leaving the invoices they cover payable. Run by billing:run.
     */
    public function expire(CarbonInterface $today): int
    {
        $stale = $this->open()
            ->where(fn ($query) => $query
                ->whereDate('due_date', '<', $today->copy()->subDays(self::EXPIRES_AFTER_DAYS))
                ->orWhereHas('items', fn ($items) => $items->where('type', InvoiceItem::TYPE_MASS_PAY)
                    ->where(fn ($covered) => $covered->whereNull('paid_invoice_id')
                        ->orWhereHas('paidInvoice', fn ($paid) => $paid->where('status', '!=', InvoiceStatus::Unpaid)))))
            ->pluck('id');

        return $this->cancel($stale->all());
    }

    /**
     * Cancels the unpaid mass payments, with nothing paid on them, that cover
     * any of the invoices in $ids, such as before they are merged.
     *
     * @param  list<int>  $ids
     */
    public function cancelCovering(array $ids): int
    {
        return $this->cancel($this->open()
            ->whereHas('items', fn ($items) => $items->where('type', InvoiceItem::TYPE_MASS_PAY)->whereIn('paid_invoice_id', $ids))
            ->pluck('id')->all());
    }

    /** @param  list<int>  $ids */
    private function cancel(array $ids): int
    {
        $cancelled = 0;

        foreach (Invoice::whereIn('id', $ids)->orderBy('id')->get() as $invoice) {
            // A payment may have landed since it was picked; then it stays.
            if ($invoice->amountPaid() === 0) {
                try {
                    $this->canceller->cancel($invoice);
                    $cancelled++;
                } catch (InvalidArgumentException) {
                    continue;
                }
            }
        }

        return $cancelled;
    }

    /** Unpaid mass payment invoices. */
    private function open(): Builder
    {
        return Invoice::where('status', InvoiceStatus::Unpaid)
            ->whereHas('items', fn ($items) => $items->where('type', InvoiceItem::TYPE_MASS_PAY));
    }
}
