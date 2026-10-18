<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Mail\ClientMailer;
use App\Models\Activity;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Payments\GatewayRegistry;
use App\Payments\PaymentFailed;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Charges an invoice's balance to a client's saved payment method through
 * whichever gateway saved it, and records the attempt so staff can see
 * failures on the invoice. A failed automatic charge emails the client.
 */
class SavedMethodCharger
{
    public function __construct(private GatewayRegistry $gateways, private ClientMailer $mailer) {}

    /**
     * @param  string  $source  PaymentAttempt::AUTO or PaymentAttempt::STAFF
     *
     * @throws InvalidArgumentException when the invoice can't be charged at all
     */
    public function charge(Invoice $invoice, PaymentMethod $method, string $source): PaymentAttempt
    {
        // One charge of an invoice at a time, so a staff click during the billing run can't charge twice.
        $lock = Cache::lock("charge-invoice-{$invoice->id}", 120);

        if (! $lock->get()) {
            throw new InvalidArgumentException('This invoice is being charged right now. Check it again in a minute.');
        }

        try {
            return $this->chargeUnlocked($invoice->refresh(), $method, $source);
        } finally {
            $lock->release();
        }
    }

    /**
     * Marks a pending attempt as failed, when the gateway reports that the
     * charge it was still processing, $reference, didn't go through.
     */
    public function pendingFailed(Invoice $invoice, string $gateway, string $reference, string $message): void
    {
        $invoice->paymentAttempts()->where('gateway', $gateway)->where('gateway_reference', $reference)->where('status', PaymentAttempt::PENDING)
            ->update(['status' => PaymentAttempt::FAILED, 'message' => $message]);
    }

    /**
     * Marks the invoice's pending attempts as succeeded once their own charge
     * is recorded on it. A payment made some other way leaves them pending.
     */
    public function pendingSettled(Invoice $invoice): void
    {
        foreach ($invoice->paymentAttempts()->where('status', PaymentAttempt::PENDING)->whereNotNull('gateway_reference')->get() as $attempt) {
            $payment = $invoice->transactions()->where('gateway', $attempt->gateway)->where('gateway_reference', $attempt->gateway_reference)->first();

            if ($payment !== null) {
                $attempt->update(['status' => PaymentAttempt::SUCCEEDED, 'transaction_id' => $payment->id]);
            }
        }
    }

    private function chargeUnlocked(Invoice $invoice, PaymentMethod $method, string $source): PaymentAttempt
    {
        $amount = $invoice->balance();

        if ($invoice->status !== InvoiceStatus::Unpaid || $amount <= 0) {
            throw new InvalidArgumentException("Invoice #{$invoice->number} has nothing left to pay.");
        }

        if ($method->client_id !== $invoice->client_id) {
            throw new InvalidArgumentException('That payment method belongs to another client.');
        }

        $gateway = $this->gateways->charger($method->gateway)
            ?? throw new InvalidArgumentException("{$method->label()} was saved with a payment gateway that isn't set up any more.");

        // The same invoice, method, day, amount and try give the same key, so
        // a retried request can't charge twice, while a later try still can.
        $tries = $invoice->paymentAttempts()->whereDate('attempted_on', today())->count();
        $key = "invoice-{$invoice->id}-method-{$method->id}-".today()->toDateString()."-{$amount}-{$tries}";

        try {
            $charge = $gateway->charge($invoice, $method, $key);
            $transaction = $charge->transaction;
            $reference = $charge->reference;
            $status = $transaction ? PaymentAttempt::SUCCEEDED : PaymentAttempt::PENDING;
            $message = null;
        } catch (PaymentFailed $e) {
            $transaction = null;
            $reference = null;
            $status = PaymentAttempt::FAILED;
            $message = $e->getMessage();
        }

        $attempt = $invoice->paymentAttempts()->create([
            'client_id' => $invoice->client_id,
            'payment_method_id' => $method->id,
            'transaction_id' => $transaction?->id,
            'gateway_reference' => $reference,
            'method_label' => $method->label(),
            'gateway' => $method->gateway,
            'currency' => $invoice->currency,
            'amount' => $amount,
            'status' => $status,
            'message' => $message,
            'source' => $source,
            'attempted_on' => today(),
        ]);

        Activity::record(
            match ($status) {
                PaymentAttempt::SUCCEEDED => 'Charged ',
                PaymentAttempt::PENDING => 'Started a charge of ',
                default => 'Could not charge ',
            }.Money::format($amount, $invoice->currency)." to {$method->label()} for invoice #{$invoice->number}"
                .($source === PaymentAttempt::AUTO ? ' (automatic)' : ''),
            $invoice,
            array_filter(['reason' => $message]),
        );

        if ($status === PaymentAttempt::FAILED && $source === PaymentAttempt::AUTO && $invoice->client) {
            $this->mailer->send($invoice->client, 'payment_failed', [
                'payment_method' => $method->label(),
                'failure_reason' => $message,
                'payment_methods_url' => route('portal.payment-methods.index'),
            ], $invoice);
        }

        return $attempt;
    }
}
