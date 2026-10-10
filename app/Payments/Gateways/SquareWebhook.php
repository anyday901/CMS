<?php

namespace App\Payments\Gateways;

use App\Billing\DisputeRecorder;
use App\Billing\RefundReconciler;
use App\Billing\RefundService;
use App\Models\Transaction;
use App\Payments\GatewayRefund;
use Illuminate\Support\Facades\Log;

/**
 * Square webhook events for Cash App Pay payments: refunds (including ones
 * issued from the Square dashboard) and disputes.
 */
class SquareWebhook
{
    public function __construct(
        private CashAppPay $cashApp,
        private RefundService $refunds,
        private RefundReconciler $reconciler,
        private DisputeRecorder $disputes,
    ) {}

    /**
     * Square signs the notification URL followed by the raw body with the
     * webhook's signature key (HMAC-SHA256, base64).
     */
    public function verify(string $body, ?string $signature): bool
    {
        $key = config('payments.square.webhook_signature_key');

        if (blank($key) || blank($signature)) {
            return false;
        }

        $url = config('payments.square.webhook_url') ?: route('webhooks.square');
        $expected = base64_encode(hash_hmac('sha256', $url.$body, $key, true));

        return hash_equals($expected, $signature);
    }

    public function handle(array $event): void
    {
        $type = (string) ($event['type'] ?? '');
        $object = $event['data']['object'] ?? [];

        match (true) {
            str_starts_with($type, 'refund.') => $this->refund($object['refund'] ?? []),
            str_starts_with($type, 'dispute.') => $this->dispute($object['dispute'] ?? []),
            default => null,
        };
    }

    private function refund(array $refund): void
    {
        $payment = $this->payment($refund['payment_id'] ?? null);

        if ($payment === null || ! isset($refund['id'])) {
            return;
        }

        $status = match ($refund['status'] ?? null) {
            'COMPLETED' => GatewayRefund::COMPLETED,
            'PENDING' => GatewayRefund::PENDING,
            default => GatewayRefund::FAILED, // FAILED or REJECTED
        };

        $recorded = Transaction::where('gateway', $this->cashApp->key())->where('gateway_reference', $refund['id'])->first();

        if ($recorded) {
            $this->reconciler->settle($recorded, $status);
        } elseif ($status !== GatewayRefund::FAILED) {
            $this->refunds->recordFromGateway($payment, (int) ($refund['amount_money']['amount'] ?? 0), new GatewayRefund($refund['id'], $status));
        }
    }

    private function dispute(array $dispute): void
    {
        // Newer API versions nest the payment id under disputed_payment.
        $payment = $this->payment($dispute['disputed_payment']['payment_id'] ?? $dispute['payment_id'] ?? null);

        if ($payment === null || ! isset($dispute['id'], $dispute['state'])) {
            return;
        }

        $this->disputes->update($payment, $dispute['id'], $dispute['state'], (int) ($dispute['amount_money']['amount'] ?? 0), $dispute['reason'] ?? null);
    }

    private function payment(?string $paymentId): ?Transaction
    {
        $payment = $paymentId === null ? null : Transaction::where('gateway', $this->cashApp->key())
            ->where('gateway_reference', $paymentId)
            ->where('amount', '>', 0)
            ->first();

        if ($payment === null) {
            Log::info('Square webhook for a payment this app did not record', ['payment' => $paymentId]);
        }

        return $payment;
    }
}
