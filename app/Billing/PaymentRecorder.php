<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Events\InvoicePaid;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Transaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PaymentRecorder
{
    public function __construct(private ServiceLifecycle $lifecycle) {}

    /**
     * Record a payment against an invoice. A repeated gateway reference returns
     * the existing transaction, so gateway callbacks can be retried safely.
     * Any amount over the invoice balance goes to the client's credit balance.
     */
    public function record(
        Invoice $invoice,
        int $amount,
        string $gateway,
        ?string $reference = null,
        int $fee = 0,
        ?string $method = null,
    ): Transaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be positive.');
        }

        try {
            return $this->recordLocked($invoice, $amount, $gateway, $reference, $fee, $method);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent callback with the same reference won the race.
            return $this->findExisting($gateway, $reference, $invoice) ?? throw $e;
        }
    }

    private function recordLocked(Invoice $invoice, int $amount, string $gateway, ?string $reference, int $fee, ?string $method): Transaction
    {
        $paidNow = false;

        $transaction = DB::transaction(function () use ($invoice, $amount, $gateway, $reference, $fee, $method, &$paidNow) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);

            if ($existing = $this->findExisting($gateway, $reference, $invoice)) {
                return $existing;
            }

            if ($invoice->status !== InvoiceStatus::Unpaid) {
                throw new InvalidArgumentException("Invoice {$invoice->number} is {$invoice->status->value}, not unpaid.");
            }

            $applied = min($amount, $invoice->balance());
            $excess = $amount - $applied;

            $transaction = $invoice->transactions()->create([
                'client_id' => $invoice->client_id,
                'gateway' => $gateway,
                'payment_method' => $method,
                'gateway_reference' => $reference,
                'currency' => $invoice->currency,
                'amount' => $applied,
                'fee' => $fee,
            ]);

            if ($excess > 0) {
                $invoice->client()->increment('credit_balance', $excess);
            }

            if ($invoice->balance() <= 0) {
                $this->markPaid($invoice);
                $paidNow = true;
            }

            return $transaction;
        });

        if ($paidNow) {
            InvoicePaid::dispatch($invoice->refresh());
        }

        return $transaction;
    }

    /**
     * The transaction already recorded under this gateway reference, if any.
     * A reference already used on a different invoice is an error, not a retry.
     */
    private function findExisting(string $gateway, ?string $reference, Invoice $invoice): ?Transaction
    {
        if ($reference === null) {
            return null;
        }

        $existing = Transaction::where('gateway', $gateway)
            ->where('gateway_reference', $reference)
            ->first();

        if ($existing && $existing->invoice_id !== $invoice->id) {
            throw new InvalidArgumentException("Reference {$reference} is already recorded on another invoice.");
        }

        return $existing;
    }

    private function markPaid(Invoice $invoice): void
    {
        $invoice->forceFill([
            'status' => InvoiceStatus::Paid,
            'paid_at' => now(),
        ])->save();

        $items = $invoice->items()
            ->where('type', InvoiceItem::TYPE_SERVICE)
            ->with('service')
            ->get();

        foreach ($items as $item) {
            $service = $item->service;

            if ($service === null) {
                continue;
            }

            if ($service->next_due_date?->equalTo($item->period_start)) {
                $service->next_due_date = $service->billing_cycle->isRecurring()
                    ? $service->billing_cycle->advance($service->next_due_date)
                    : null;
                $service->save();
            }

            if ($service->status === ServiceStatus::Pending) {
                $this->lifecycle->activate($service);
            } elseif (
                $service->status === ServiceStatus::Suspended
                && $service->suspension_reason === ServiceLifecycle::REASON_OVERDUE
                && ! $this->hasOverdueInvoices($service->id)
            ) {
                $this->lifecycle->unsuspend($service);
            }
        }
    }

    private function hasOverdueInvoices(int $serviceId): bool
    {
        return Invoice::where('status', InvoiceStatus::Unpaid)
            ->whereDate('due_date', '<', today())
            ->whereHas('items', fn ($items) => $items->where('service_id', $serviceId))
            ->exists();
    }
}
