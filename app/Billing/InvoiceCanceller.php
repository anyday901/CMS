<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Activity;
use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InvoiceCanceller
{
    /**
     * Cancels a draft or unpaid invoice with no payments. Pending services on it are
     * cancelled too, since they were waiting on this invoice to activate.
     * Takes the same invoice lock as PaymentRecorder so a payment and a
     * cancellation cannot both succeed.
     */
    public function cancel(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);

            if (! in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Unpaid], true) || $invoice->amountPaid() !== 0) {
                throw new InvalidArgumentException('Only draft or unpaid invoices with no payments can be cancelled.');
            }

            $invoice->update(['status' => InvoiceStatus::Cancelled]);
            Activity::record("Cancelled invoice #{$invoice->number}", $invoice);

            Service::whereIn('id', $invoice->items()->whereNotNull('service_id')->select('service_id'))
                ->where('status', ServiceStatus::Pending)
                ->update(['status' => ServiceStatus::Cancelled]);
        });
    }
}
