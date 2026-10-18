<?php

namespace App\Payments\Gateways;

use App\Billing\PaymentRecorder;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Payments\ChargesSavedMethods;
use App\Payments\Gateway;
use App\Payments\GatewayRefund;
use App\Payments\PaymentFailed;
use App\Payments\RefundsPayments;
use App\Payments\SavedCharge;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Cards through Stripe (PaymentIntents and the Payment Element), with cards
 * saved on a Stripe customer for paying later and for automatic charging.
 * Card details go from the browser straight to Stripe; this app only keeps
 * Stripe's ids and the brand, last four digits and expiry.
 *
 * StripeApi makes the requests; StripeCards keeps customers and cards.
 */
class Stripe implements ChargesSavedMethods, Gateway, RefundsPayments
{
    private const PAYMENT_INTENTS = '/v1/payment_intents';

    public const KEY = 'stripe';

    public function __construct(private PaymentRecorder $payments, private StripeApi $api, private StripeCards $cards) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Card';
    }

    public function isEnabled(): bool
    {
        return filled(config('payments.stripe.publishable_key')) && filled(config('payments.stripe.secret_key'));
    }

    public function view(): string
    {
        return 'payments.stripe';
    }

    public function viewData(Invoice $invoice): array
    {
        return [
            'publishableKey' => config('payments.stripe.publishable_key'),
            'stripeAmount' => StripeApi::toStripe($invoice->balance(), $invoice->currency),
            'savedMethods' => $invoice->client->paymentMethods()->where('gateway', self::KEY)->get()->reject->isExpired(),
        ];
    }

    public function addMethodView(): string
    {
        return 'payments.stripe-add-card';
    }

    public function addMethodViewData(Client $client): array
    {
        return ['publishableKey' => config('payments.stripe.publishable_key'), 'currency' => strtolower($client->currency)];
    }

    /**
     * Creates a PaymentIntent for the invoice's balance, for the portal's
     * card form to confirm, and returns its client secret. With $save the
     * card is kept on the client's Stripe customer for later payments.
     */
    public function createIntent(Invoice $invoice, bool $save): string
    {
        $customer = $save ? $this->cards->customerFor($invoice->client) : $invoice->client->gatewayCustomer(self::KEY);

        $response = $this->api->request()->post(self::PAYMENT_INTENTS, $this->intentParams($invoice) + array_filter([
            'automatic_payment_methods' => ['enabled' => 'true'],
            'customer' => $customer,
            'setup_future_usage' => $save ? 'off_session' : null,
        ]));

        return $this->api->ok($response, 'create a payment')->json('client_secret');
    }

    /**
     * Pays the invoice with a card the client saved, while they're on the
     * page. When their bank wants them to confirm it, returns the client
     * secret for Stripe.js to show that step; otherwise "paid" or "processing".
     *
     * @return array{status: string, client_secret?: string}
     */
    public function payWithSaved(Invoice $invoice, PaymentMethod $method): array
    {
        // The same lock as automatic and staff charges, so a double click or a
        // billing run at the same moment can't charge the invoice twice.
        $lock = Cache::lock("charge-invoice-{$invoice->id}", 120);

        if (! $lock->get()) {
            throw new PaymentFailed(__('This invoice is being charged right now. Please check it again in a minute.'));
        }

        try {
            return $this->payWithSavedUnlocked($invoice->refresh(), $method);
        } finally {
            $lock->release();
        }
    }

    /** @return array{status: string, client_secret?: string} */
    private function payWithSavedUnlocked(Invoice $invoice, PaymentMethod $method): array
    {
        $amount = $invoice->balance();

        if ($invoice->status !== InvoiceStatus::Unpaid || $amount <= 0) {
            throw new PaymentFailed(__('This invoice has nothing left to pay.'));
        }

        // A repeat while Stripe is still processing the first payment gets
        // that same payment back; a new key is used only after a decline.
        $declines = "stripe-saved-declines-{$invoice->id}";
        $key = "invoice-{$invoice->id}-saved-{$method->id}-{$amount}-".Cache::get($declines, 0);

        $response = $this->api->request()
            ->withHeaders(['Idempotency-Key' => $key])
            ->post(self::PAYMENT_INTENTS, $this->intentParams($invoice) + [
                'customer' => $method->gateway_customer_id,
                'payment_method' => $method->gateway_reference,
                'payment_method_types' => [$method->type],
                'confirm' => 'true',
                'expand' => ['latest_charge.balance_transaction'],
            ]);
        $intent = $response->json();

        if ($response->failed()) {
            Log::warning('Stripe could not charge a saved card', ['invoice' => $invoice->id, 'status' => $response->status(), 'error' => $response->json('error.code')]);
            Cache::put($declines, (int) Cache::get($declines, 0) + 1, now()->addDay());

            throw new PaymentFailed($this->api->declineMessage($response->json('error')));
        }

        if (($intent['status'] ?? null) === 'requires_action') {
            return ['status' => 'requires_action', 'client_secret' => $intent['client_secret']];
        }

        return ['status' => $this->settle($invoice, $intent) ? 'paid' : 'processing'];
    }

    /**
     * Checks a PaymentIntent the browser confirmed and records the payment.
     * Returns null while Stripe is still processing it; the webhook records
     * it later.
     */
    public function confirm(Invoice $invoice, string $intentId): ?Transaction
    {
        $intent = $this->api->retrieve('payment_intents', 'pi', $intentId, ['payment_method', 'latest_charge.balance_transaction']);

        if (($intent['metadata']['invoice_id'] ?? null) !== (string) $invoice->id) {
            Log::warning('Stripe payment does not match invoice', ['invoice' => $invoice->id, 'intent' => $intentId]);

            throw new PaymentFailed(__('This payment does not match the invoice. Please contact us.'));
        }

        return $this->settle($invoice, $intent);
    }

    public function charge(Invoice $invoice, PaymentMethod $method, string $idempotencyKey): SavedCharge
    {
        $response = $this->api->request()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post(self::PAYMENT_INTENTS, $this->intentParams($invoice) + [
                'customer' => $method->gateway_customer_id,
                'payment_method' => $method->gateway_reference,
                'payment_method_types' => [$method->type],
                'off_session' => 'true',
                'confirm' => 'true',
                'expand' => ['latest_charge.balance_transaction'],
            ]);

        if ($response->failed()) {
            Log::warning('Stripe automatic charge failed', ['invoice' => $invoice->id, 'status' => $response->status(), 'error' => $response->json('error.code'), 'decline' => $response->json('error.decline_code')]);

            throw new PaymentFailed($this->api->declineMessage($response->json('error')));
        }

        $intent = $response->json();

        if (($intent['status'] ?? null) === 'requires_action') {
            throw new PaymentFailed(__('Your bank asked to confirm this payment. Please pay the invoice in the client area.'));
        }

        return new SavedCharge($this->settle($invoice, $intent), (string) $intent['id']);
    }

    public function removeMethod(PaymentMethod $method): void
    {
        $this->api->id('pm', $method->gateway_reference);
        $response = $this->api->request()->post("/v1/payment_methods/{$method->gateway_reference}/detach");

        // Already removed at Stripe, from its dashboard for example.
        if ($response->failed() && $response->status() !== 404 && ! str_contains((string) $response->json('error.message'), 'not attached')) {
            Log::error('Stripe could not remove a card', ['method' => $method->id, 'status' => $response->status(), 'error' => $response->json('error.message')]);

            throw new PaymentFailed(__('Stripe could not remove this card. Please try again later.'));
        }
    }

    public function refund(Transaction $payment, int $amount, string $idempotencyKey): GatewayRefund
    {
        $reference = (string) $payment->gateway_reference;
        $field = str_starts_with($reference, 'ch_') ? 'charge' : 'payment_intent';
        $this->api->id($field === 'charge' ? 'ch' : 'pi', $reference);

        $response = $this->api->request()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/v1/refunds', [$field => $reference, 'amount' => StripeApi::toStripe($amount, $payment->currency)]);

        if ($response->failed()) {
            Log::error('Stripe refund failed', ['transaction' => $payment->id, 'status' => $response->status(), 'error' => $response->json('error')]);

            throw new PaymentFailed('Stripe refused the refund: '.($response->json('error.message') ?? 'unknown error'));
        }

        return $this->refundResult($response->json());
    }

    public function refundStatus(string $refundId): GatewayRefund
    {
        return $this->refundResult($this->api->retrieve('refunds', 're|pyr', $refundId));
    }

    /**
     * Records a PaymentIntent's outcome: the payment when it succeeded, null
     * while it's processing, and an error when it didn't go through. A card
     * the client asked to keep is saved too.
     */
    private function settle(Invoice $invoice, array $intent): ?Transaction
    {
        $status = $intent['status'] ?? null;

        if ($status === 'processing') {
            return null;
        }

        if ($status !== 'succeeded') {
            throw new PaymentFailed($this->api->declineMessage($intent['last_payment_error'] ?? null, 'The payment was not completed. Please try again.'));
        }

        if (strtoupper((string) ($intent['currency'] ?? '')) !== $invoice->currency) {
            Log::critical('Stripe payment in another currency than its invoice', ['invoice' => $invoice->id, 'intent' => $intent['id']]);

            throw new PaymentFailed(__('This payment was made in the wrong currency. Please contact us.'));
        }

        if (($intent['setup_future_usage'] ?? null) && is_array($intent['payment_method'] ?? null) && filled($intent['customer'] ?? null) && $invoice->client) {
            $this->cards->saveMethod($invoice->client, $intent['payment_method'], is_array($intent['customer']) ? $intent['customer']['id'] : $intent['customer']);
        }

        try {
            return $this->payments->record(
                $invoice,
                StripeApi::fromStripe((int) $intent['amount_received'], $invoice->currency),
                $this->key(),
                $intent['id'],
                StripeApi::fee($intent, $invoice->currency),
            );
        } catch (InvalidArgumentException $e) {
            // The money has moved but the invoice can't take it (already paid
            // or cancelled). Staff need to apply or refund it by hand.
            Log::critical('Stripe payment received but not applied', ['invoice' => $invoice->id, 'intent' => $intent['id'], 'reason' => $e->getMessage()]);

            throw new PaymentFailed(__('We received your payment but could not apply it to this invoice automatically. We will sort it out and contact you.'));
        }
    }

    private function intentParams(Invoice $invoice): array
    {
        return [
            'amount' => StripeApi::toStripe($invoice->balance(), $invoice->currency),
            'currency' => strtolower($invoice->currency),
            'description' => config('app.name')." invoice #{$invoice->number}",
            'metadata' => ['invoice_id' => (string) $invoice->id, 'client_id' => (string) $invoice->client_id],
        ];
    }

    private function refundResult(array $refund): GatewayRefund
    {
        return new GatewayRefund($refund['id'], StripeApi::refundState($refund['status'] ?? null));
    }
}
