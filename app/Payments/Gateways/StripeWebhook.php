<?php

namespace App\Payments\Gateways;

use App\Billing\DisputeRecorder;
use App\Billing\PaymentMethods;
use App\Billing\RefundReconciler;
use App\Billing\RefundService;
use App\Billing\SavedMethodCharger;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Payments\GatewayRefund;
use App\Payments\PaymentFailed;
use Illuminate\Support\Facades\Log;

/**
 * Stripe webhook events: payments that finished after the client left the
 * page, refunds (including ones issued from the Stripe dashboard), disputes,
 * and cards added or removed outside the portal.
 */
class StripeWebhook
{
    public function __construct(
        private Stripe $stripe,
        private RefundService $refunds,
        private RefundReconciler $reconciler,
        private DisputeRecorder $disputes,
        private SavedMethodCharger $charger,
        private PaymentMethods $methods,
    ) {}

    public function handle(array $event): void
    {
        $type = (string) ($event['type'] ?? '');
        $object = (array) ($event['data']['object'] ?? []);

        match (true) {
            $type === 'payment_intent.succeeded' => $this->succeeded($object),
            $type === 'payment_intent.payment_failed' => $this->failed($object),
            in_array($type, ['charge.refunded', 'charge.refund.updated', 'refund.created', 'refund.updated', 'refund.failed'], true) => $this->refunds($object['payment_intent'] ?? null),
            str_starts_with($type, 'charge.dispute.') => $this->dispute($object),
            $type === 'setup_intent.succeeded' => $this->cardAdded($object),
            $type === 'payment_method.detached' => $this->cardRemoved($object),
            default => null,
        };
    }

    private function succeeded(array $intent): void
    {
        $invoice = $this->invoice($intent);

        if ($invoice === null) {
            return;
        }

        try {
            // Looked up again with its card and fee; a payment already recorded is returned as it is.
            $this->stripe->confirm($invoice, (string) $intent['id']);
        } catch (PaymentFailed) {
            // Already logged; acknowledge so Stripe stops retrying.
        }
    }

    private function failed(array $intent): void
    {
        if ($invoice = $this->invoice($intent)) {
            $this->charger->pendingFailed($invoice, Stripe::KEY, (string) ($intent['id'] ?? ''), (string) ($intent['last_payment_error']['message'] ?? 'The payment did not go through.'));
        }
    }

    private function refunds(?string $intentId): void
    {
        $payment = $this->payment($intentId);

        if ($payment === null) {
            return;
        }

        foreach ($this->stripe->refundsFor($intentId) as $refund) {
            if (! isset($refund['id'])) {
                continue;
            }

            $status = Stripe::refundState($refund['status'] ?? null);
            $recorded = Transaction::where('gateway', Stripe::KEY)->where('gateway_reference', $refund['id'])->first();

            if ($recorded) {
                $this->reconciler->settle($recorded, $status);
            } elseif ($status !== GatewayRefund::FAILED) {
                $this->refunds->recordFromGateway($payment, Stripe::fromStripe((int) ($refund['amount'] ?? 0), $payment->currency), new GatewayRefund($refund['id'], $status));
            }
        }
    }

    private function dispute(array $dispute): void
    {
        $payment = $this->payment($dispute['payment_intent'] ?? null);

        if ($payment === null || ! isset($dispute['id'], $dispute['status'])) {
            return;
        }

        $this->disputes->update(
            $payment,
            $dispute['id'],
            // An inquiry closed without becoming a chargeback keeps the money with you.
            $dispute['status'] === 'warning_closed' ? 'closed' : $dispute['status'],
            Stripe::fromStripe((int) ($dispute['amount'] ?? 0), $payment->currency),
            $dispute['reason'] ?? null,
        );
    }

    /** A card saved through a SetupIntent that finished after the client left the page. */
    private function cardAdded(array $intent): void
    {
        $client = Client::find($intent['metadata']['client_id'] ?? null);

        if ($client === null || ! isset($intent['id'])) {
            return;
        }

        try {
            $this->stripe->confirmSetup($client, (string) $intent['id']);
        } catch (PaymentFailed $e) {
            Log::warning('Could not save a card from a Stripe webhook', ['client' => $client->id, 'reason' => $e->getMessage()]);
        }
    }

    /** A card removed in the Stripe dashboard can't be charged any more. */
    private function cardRemoved(array $method): void
    {
        $saved = PaymentMethod::where('gateway', Stripe::KEY)->where('gateway_reference', $method['id'] ?? null)->first();

        if ($saved) {
            $this->methods->forget($saved);
        }
    }

    private function invoice(array $intent): ?Invoice
    {
        $invoice = isset($intent['id']) ? Invoice::find($intent['metadata']['invoice_id'] ?? null) : null;

        if ($invoice === null) {
            Log::info('Stripe webhook for a payment that is not for an invoice here', ['intent' => $intent['id'] ?? null]);
        }

        return $invoice;
    }

    private function payment(?string $intentId): ?Transaction
    {
        $payment = $intentId === null ? null : Transaction::where('gateway', Stripe::KEY)
            ->where('gateway_reference', $intentId)
            ->where('amount', '>', 0)
            ->first();

        if ($payment === null) {
            Log::info('Stripe webhook for a payment this app did not record', ['intent' => $intentId]);
        }

        return $payment;
    }
}
