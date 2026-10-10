<?php

namespace App\Billing;

use App\Models\Activity;
use App\Models\Transaction;
use App\Payments\GatewayRefund;
use App\Support\Money;
use Illuminate\Support\Facades\Log;

/**
 * Tracks chargebacks and disputes the gateway reports on a payment. A lost or
 * accepted dispute means the money went back to the payer, so it is recorded
 * as a refund of the disputed amount.
 */
class DisputeRecorder
{
    public const LOST_STATES = ['lost', 'accepted'];

    public function __construct(private RefundService $refunds) {}

    public function update(Transaction $payment, string $disputeId, string $state, int $amount, ?string $reason = null): void
    {
        $state = strtolower($state);

        if ($payment->dispute_status === $state) {
            return; // Repeated webhook.
        }

        $payment->update(['dispute_status' => $state]);

        $where = $payment->invoice ? " on invoice #{$payment->invoice->number}" : '';
        Activity::record(
            'Dispute on a '.Money::format($payment->amount, $payment->currency)." {$payment->methodLabel()} payment{$where}: ".str_replace('_', ' ', $state),
            $payment,
            array_filter(['dispute' => $disputeId, 'amount' => $amount, 'reason' => $reason]),
        );
        Log::warning('Payment dispute updated', ['transaction' => $payment->id, 'dispute' => $disputeId, 'state' => $state]);

        if (in_array($state, self::LOST_STATES, true)) {
            $this->refunds->recordFromGateway($payment, min($amount, $payment->refundable()), new GatewayRefund("dispute-{$disputeId}", GatewayRefund::COMPLETED));
        }
    }
}
