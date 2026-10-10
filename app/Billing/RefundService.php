<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Payments\GatewayRefund;
use App\Payments\GatewayRegistry;
use App\Payments\PaymentFailed;
use App\Payments\RefundsPayments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Refunds a payment, recorded as a negative transaction linked to it.
 *
 * - gateway: sends the money back through the gateway's API (PayPal, Square).
 * - manual: staff already sent the money back outside the app; just record it.
 * - credit: moves the money to the client's credit balance.
 *
 * A gateway refund the gateway reports as pending is recorded as pending and
 * settled later by RefundReconciler. A fully refunded invoice is marked
 * refunded. Services are left as they are, so staff decide whether to
 * suspend or cancel them.
 */
class RefundService
{
    public const MODES = ['gateway', 'manual', 'credit'];

    public function __construct(private GatewayRegistry $gateways) {}

    public function refund(Transaction $payment, int $amount, string $mode): Transaction
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("Unknown refund mode {$mode}.");
        }

        return DB::transaction(function () use ($payment, $amount, $mode) {
            // Locking the invoice serializes refunds of the same payment.
            $invoice = $payment->invoice_id ? Invoice::lockForUpdate()->find($payment->invoice_id) : null;
            $payment = Transaction::findOrFail($payment->id);
            $this->ensureRefundable($payment, $amount, $mode);

            $sent = $mode === 'gateway' ? $this->refundThroughGateway($payment, $amount) : null;

            try {
                return $this->record($payment, $invoice, $amount, $mode, $sent);
            } catch (\Throwable $e) {
                if ($sent !== null) {
                    // The money already went back, so staff must record this by hand.
                    Log::critical('Gateway refund sent but not recorded', ['transaction' => $payment->id, 'refund' => $sent->id, 'reason' => $e->getMessage()]);
                }

                throw $e;
            }
        });
    }

    private function ensureRefundable(Transaction $payment, int $amount, string $mode): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Refund amount must be positive.');
        }

        if ($amount > $payment->refundable()) {
            throw new InvalidArgumentException('That is more than is left to refund on this payment.');
        }

        if ($payment->gateway === CreditApplier::GATEWAY && $mode !== 'credit') {
            throw new InvalidArgumentException('A payment made with account credit can only be refunded to credit.');
        }
    }

    private function record(Transaction $payment, ?Invoice $invoice, int $amount, string $mode, ?GatewayRefund $sent): Transaction
    {
        $refund = Transaction::create([
            'client_id' => $payment->client_id,
            'invoice_id' => $payment->invoice_id,
            'refund_of_id' => $payment->id,
            'gateway' => $mode === 'credit' ? CreditApplier::GATEWAY : $payment->gateway,
            'payment_method' => $mode === 'credit' ? null : $payment->payment_method,
            'gateway_reference' => $sent?->id,
            'currency' => $payment->currency,
            'amount' => -$amount,
            'pending' => $sent?->pending() ?? false,
        ]);

        if ($mode === 'credit') {
            $payment->client()->increment('credit_balance', $amount);
        }

        if ($invoice && $invoice->status === InvoiceStatus::Paid && $invoice->amountPaid() <= 0) {
            $invoice->forceFill(['status' => InvoiceStatus::Refunded])->save();
        }

        return $refund;
    }

    private function refundThroughGateway(Transaction $payment, int $amount): GatewayRefund
    {
        $gateway = $this->gateways->find($payment->gateway);

        if (! $gateway instanceof RefundsPayments || $payment->gateway_reference === null) {
            throw new InvalidArgumentException('This payment can\'t be refunded automatically. Refund it outside the app and record it instead.');
        }

        // Same payment, amount and refund count give the same key, so a retried
        // request can't refund twice, while a second partial refund still can.
        $key = "refund-{$payment->id}-{$payment->refunds()->count()}-{$amount}";

        try {
            $sent = $gateway->refund($payment, $amount, $key);
        } catch (PaymentFailed $e) {
            throw new InvalidArgumentException($e->getMessage(), previous: $e);
        }

        Log::info('Gateway refund sent', ['transaction' => $payment->id, 'refund' => $sent->id, 'status' => $sent->status, 'amount' => $amount]);

        return $sent;
    }
}
