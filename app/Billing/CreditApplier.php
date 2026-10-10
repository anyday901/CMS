<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/** Pays invoices from the client's credit balance. */
class CreditApplier
{
    public const GATEWAY = 'credit';

    public function __construct(private PaymentRecorder $payments, private CreditLedger $ledger) {}

    /**
     * Applies as much credit as the invoice balance allows, optionally capped
     * at $max. Returns null when there is nothing to apply.
     */
    public function apply(Invoice $invoice, ?int $max = null): ?Transaction
    {
        return DB::transaction(function () use ($invoice, $max) {
            // Invoice first, then client: the same order PaymentRecorder uses.
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            $client = Client::lockForUpdate()->findOrFail($invoice->client_id);

            if ($invoice->status !== InvoiceStatus::Unpaid || $client->currency !== $invoice->currency) {
                return null;
            }

            $amount = min($client->credit_balance, $invoice->balance(), $max ?? PHP_INT_MAX);

            if ($amount <= 0) {
                return null;
            }

            $payment = $this->payments->record($invoice, $amount, self::GATEWAY);
            $this->ledger->change($client->id, -$amount, "Paid invoice #{$invoice->number}", $invoice, $payment);

            return $payment;
        });
    }

    /** Applies credit automatically to a newly created invoice when enabled. */
    public function applyIfEnabled(?Invoice $invoice): void
    {
        if ($invoice && config('billing.apply_credit_automatically')) {
            $this->apply($invoice);
        }
    }
}
