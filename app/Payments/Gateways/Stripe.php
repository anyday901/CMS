<?php

namespace App\Payments\Gateways;

use App\Billing\PaymentRecorder;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Payments\ChargesSavedMethods;
use App\Payments\Gateway;
use App\Payments\GatewayRefund;
use App\Payments\PaymentFailed;
use App\Payments\RefundsPayments;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Cards through Stripe (PaymentIntents and the Payment Element), with cards
 * saved on a Stripe customer for paying later and for automatic charging.
 * Card details go from the browser straight to Stripe; this app only keeps
 * Stripe's ids and the brand, last four digits and expiry.
 *
 * Amounts: this app keeps every currency in hundredths. Stripe wants whole
 * units for zero-decimal currencies such as JPY and thousandths for
 * three-decimal ones such as KWD, so toStripe() and fromStripe() convert.
 */
class Stripe implements ChargesSavedMethods, Gateway, RefundsPayments
{
    private const PAYMENT_INTENTS = '/v1/payment_intents';

    public const KEY = 'stripe';

    /** Currencies Stripe takes in whole units. */
    public const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** Currencies Stripe takes in thousandths. */
    public const THREE_DECIMAL = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    /** Stripe ids look like "pi_3Abc..."; anything else could change the request path. */
    private const ID_PATTERN = '/^(%s)_[A-Za-z0-9]{1,250}$/';

    private const DECLINED = 'Your card was declined. Please try another card or another way to pay.';

    public function __construct(private PaymentRecorder $payments) {}

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
            'stripeAmount' => self::toStripe($invoice->balance(), $invoice->currency),
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

    /** Our hundredths as Stripe's amount in the currency's smallest unit. */
    public static function toStripe(int $cents, string $currency): int
    {
        return match (true) {
            // Rounded up, so the payment covers the balance; anything over goes to credit.
            in_array(strtoupper($currency), self::ZERO_DECIMAL, true) => intdiv($cents + 99, 100),
            in_array(strtoupper($currency), self::THREE_DECIMAL, true) => $cents * 10,
            default => $cents,
        };
    }

    /** Stripe's amount in the currency's smallest unit as our hundredths. */
    public static function fromStripe(int $amount, string $currency): int
    {
        return match (true) {
            in_array(strtoupper($currency), self::ZERO_DECIMAL, true) => $amount * 100,
            in_array(strtoupper($currency), self::THREE_DECIMAL, true) => intdiv($amount + 5, 10),
            default => $amount,
        };
    }

    /**
     * Creates a PaymentIntent for the invoice's balance, for the portal's
     * card form to confirm, and returns its client secret. With $save the
     * card is kept on the client's Stripe customer for later payments.
     */
    public function createIntent(Invoice $invoice, bool $save): string
    {
        $customer = $save ? $this->customerFor($invoice->client) : $invoice->client->gatewayCustomer(self::KEY);

        $response = $this->api()->post(self::PAYMENT_INTENTS, $this->intentParams($invoice) + array_filter([
            'automatic_payment_methods' => ['enabled' => 'true'],
            'customer' => $customer,
            'setup_future_usage' => $save ? 'off_session' : null,
        ]));

        return $this->ok($response, 'create a payment')->json('client_secret');
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
        $response = $this->api()->post(self::PAYMENT_INTENTS, $this->intentParams($invoice) + [
            'customer' => $method->gateway_customer_id,
            'payment_method' => $method->gateway_reference,
            'payment_method_types' => [$method->type],
            'confirm' => 'true',
            'expand' => ['latest_charge.balance_transaction'],
        ]);
        $intent = $response->json();

        if ($response->failed()) {
            Log::warning('Stripe could not charge a saved card', ['invoice' => $invoice->id, 'status' => $response->status(), 'error' => $response->json('error.code')]);

            throw new PaymentFailed($this->declineMessage($response->json('error')));
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
        $intent = $this->retrieve('payment_intents', 'pi', $intentId, ['payment_method', 'latest_charge.balance_transaction']);

        if (($intent['metadata']['invoice_id'] ?? null) !== (string) $invoice->id) {
            Log::warning('Stripe payment does not match invoice', ['invoice' => $invoice->id, 'intent' => $intentId]);

            throw new PaymentFailed('This payment does not match the invoice. Please contact us.');
        }

        return $this->settle($invoice, $intent);
    }

    /** Creates a SetupIntent for adding a card in the portal and returns its client secret. */
    public function createSetupIntent(Client $client): string
    {
        $response = $this->api()->post('/v1/setup_intents', [
            'customer' => $this->customerFor($client),
            'usage' => 'off_session',
            'payment_method_types' => ['card'],
            'metadata' => ['client_id' => (string) $client->id],
        ]);

        return $this->ok($response, 'start adding a card')->json('client_secret');
    }

    /** Saves the card of a SetupIntent the browser confirmed. */
    public function confirmSetup(Client $client, string $setupIntentId): PaymentMethod
    {
        $intent = $this->retrieve('setup_intents', 'seti', $setupIntentId, ['payment_method']);

        if (($intent['metadata']['client_id'] ?? null) !== (string) $client->id || ! is_array($intent['payment_method'] ?? null)) {
            throw new PaymentFailed('This card could not be found. Please try again.');
        }

        if (($intent['status'] ?? null) !== 'succeeded') {
            throw new PaymentFailed($this->declineMessage($intent['last_setup_error'] ?? null, 'Your card could not be saved. Please try another card.'));
        }

        return $this->saveMethod($client, $intent['payment_method'], (string) $intent['customer']);
    }

    public function charge(Invoice $invoice, PaymentMethod $method, string $idempotencyKey): ?Transaction
    {
        $response = $this->api()
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

            throw new PaymentFailed($this->declineMessage($response->json('error')));
        }

        $intent = $response->json();

        if (($intent['status'] ?? null) === 'requires_action') {
            throw new PaymentFailed('Your bank asked to confirm this payment. Please pay the invoice in the client area.');
        }

        return $this->settle($invoice, $intent);
    }

    public function removeMethod(PaymentMethod $method): void
    {
        $this->id('pm', $method->gateway_reference);
        $response = $this->api()->post("/v1/payment_methods/{$method->gateway_reference}/detach");

        // Already removed at Stripe, from its dashboard for example.
        if ($response->failed() && $response->status() !== 404 && ! str_contains((string) $response->json('error.message'), 'not attached')) {
            Log::error('Stripe could not remove a card', ['method' => $method->id, 'status' => $response->status(), 'error' => $response->json('error.message')]);

            throw new PaymentFailed('Stripe could not remove this card. Please try again later.');
        }
    }

    public function refund(Transaction $payment, int $amount, string $idempotencyKey): GatewayRefund
    {
        $reference = (string) $payment->gateway_reference;
        $field = str_starts_with($reference, 'ch_') ? 'charge' : 'payment_intent';
        $this->id($field === 'charge' ? 'ch' : 'pi', $reference);

        $response = $this->api()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/v1/refunds', [$field => $reference, 'amount' => self::toStripe($amount, $payment->currency)]);

        if ($response->failed()) {
            Log::error('Stripe refund failed', ['transaction' => $payment->id, 'status' => $response->status(), 'error' => $response->json('error')]);

            throw new PaymentFailed('Stripe refused the refund: '.($response->json('error.message') ?? 'unknown error'));
        }

        return $this->refundResult($response->json());
    }

    public function refundStatus(string $refundId): GatewayRefund
    {
        return $this->refundResult($this->retrieve('refunds', 're|pyr', $refundId));
    }

    /**
     * Every refund of a payment, as Stripe reports them.
     *
     * @return list<array<string, mixed>>
     */
    public function refundsFor(string $intentId): array
    {
        $this->id('pi', $intentId);
        $response = $this->api()->get('/v1/refunds', ['payment_intent' => $intentId, 'limit' => 100]);

        return (array) $this->ok($response, 'list refunds')->json('data', []);
    }

    /** What our refund statuses call a Stripe refund's status. */
    public static function refundState(?string $status): string
    {
        return match ($status) {
            'succeeded' => GatewayRefund::COMPLETED,
            'pending', 'requires_action' => GatewayRefund::PENDING,
            default => GatewayRefund::FAILED, // failed or canceled
        };
    }

    /**
     * Checks a webhook's Stripe-Signature header: an HMAC-SHA256 of
     * "{timestamp}.{body}" with the endpoint's signing secret, and a
     * timestamp close enough to now that an old request can't be replayed.
     */
    public function verifyWebhook(string $body, ?string $header, ?int $now = null): bool
    {
        $secret = config('payments.stripe.webhook_secret');

        if (blank($secret) || blank($header)) {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            match ($name) {
                't' => $timestamp = ctype_digit($value) ? (int) $value : null,
                'v1' => $signatures[] = $value,
                default => null,
            };
        }

        if ($timestamp === null || $signatures === [] || abs(($now ?? time()) - $timestamp) > (int) config('payments.stripe.webhook_tolerance', 300)) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$body}", (string) $secret);

        return collect($signatures)->contains(fn (string $signature) => hash_equals($expected, $signature));
    }

    /** The client's Stripe customer, created the first time they save a card. */
    public function customerFor(Client $client): string
    {
        if ($customer = $client->gatewayCustomer(self::KEY)) {
            return $customer;
        }

        $response = $this->api()
            ->withHeaders(['Idempotency-Key' => "customer-client-{$client->id}"])
            ->post('/v1/customers', array_filter([
                'email' => $client->email,
                'name' => $client->fullName(),
                'metadata' => ['client_id' => (string) $client->id],
            ]));

        $customer = (string) $this->ok($response, 'create a customer')->json('id');
        $client->setGatewayCustomer(self::KEY, $customer);

        return $customer;
    }

    /** Keeps a card Stripe has attached to the client's customer. The first one becomes the default. */
    public function saveMethod(Client $client, array $method, string $customer): PaymentMethod
    {
        return DB::transaction(function () use ($client, $method, $customer) {
            $saved = PaymentMethod::firstOrNew(['gateway' => self::KEY, 'gateway_reference' => $method['id']]);

            if ($saved->exists && $saved->client_id !== $client->id) {
                throw new PaymentFailed('This card could not be saved. Please contact us.');
            }

            $card = $method['card'] ?? [];
            $saved->fill([
                'client_id' => $client->id,
                'gateway_customer_id' => $customer,
                'type' => $method['type'] ?? 'card',
                'brand' => $card['brand'] ?? null,
                'last4' => $card['last4'] ?? null,
                'exp_month' => $card['exp_month'] ?? null,
                'exp_year' => $card['exp_year'] ?? null,
            ])->save();

            if (! $client->paymentMethods()->where('is_default', true)->exists()) {
                $saved->makeDefault();
            }

            return $saved;
        });
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
            throw new PaymentFailed($this->declineMessage($intent['last_payment_error'] ?? null, 'The payment was not completed. Please try again.'));
        }

        if (strtoupper((string) ($intent['currency'] ?? '')) !== $invoice->currency) {
            Log::critical('Stripe payment in another currency than its invoice', ['invoice' => $invoice->id, 'intent' => $intent['id']]);

            throw new PaymentFailed('This payment was made in the wrong currency. Please contact us.');
        }

        if (($intent['setup_future_usage'] ?? null) && is_array($intent['payment_method'] ?? null) && filled($intent['customer'] ?? null) && $invoice->client) {
            $this->saveMethod($invoice->client, $intent['payment_method'], is_array($intent['customer']) ? $intent['customer']['id'] : $intent['customer']);
        }

        try {
            return $this->payments->record(
                $invoice,
                self::fromStripe((int) $intent['amount_received'], $invoice->currency),
                $this->key(),
                $intent['id'],
                $this->fee($intent, $invoice->currency),
            );
        } catch (InvalidArgumentException $e) {
            // The money has moved but the invoice can't take it (already paid
            // or cancelled). Staff need to apply or refund it by hand.
            Log::critical('Stripe payment received but not applied', ['invoice' => $invoice->id, 'intent' => $intent['id'], 'reason' => $e->getMessage()]);

            throw new PaymentFailed('We received your payment but could not apply it to this invoice automatically. We will sort it out and contact you.');
        }
    }

    /** Stripe's fee from the charge's balance transaction, when it's in the invoice's currency. */
    private function fee(array $intent, string $currency): int
    {
        $balance = $intent['latest_charge']['balance_transaction'] ?? null;

        if (! is_array($balance) || strtoupper((string) ($balance['currency'] ?? '')) !== $currency) {
            return 0;
        }

        return self::fromStripe((int) ($balance['fee'] ?? 0), $currency);
    }

    private function intentParams(Invoice $invoice): array
    {
        return [
            'amount' => self::toStripe($invoice->balance(), $invoice->currency),
            'currency' => strtolower($invoice->currency),
            'description' => config('app.name')." invoice #{$invoice->number}",
            'metadata' => ['invoice_id' => (string) $invoice->id, 'client_id' => (string) $invoice->client_id],
        ];
    }

    /** A decline or error message from Stripe that is fine to show the client. */
    private function declineMessage(?array $error, string $default = self::DECLINED): string
    {
        return match (true) {
            ($error['code'] ?? null) === 'authentication_required' => 'Your bank asked to confirm this payment. Please pay the invoice in the client area.',
            // Card errors are written for the cardholder; other errors could show how the app talks to Stripe.
            ($error['type'] ?? null) === 'card_error' && filled($error['message'] ?? null) => $error['message'],
            default => $default,
        };
    }

    private function refundResult(array $refund): GatewayRefund
    {
        return new GatewayRefund($refund['id'], self::refundState($refund['status'] ?? null));
    }

    /** @param  list<string>  $expand */
    private function retrieve(string $resource, string $prefix, string $id, array $expand = []): array
    {
        $this->id($prefix, $id);
        $response = $this->api()->get("/v1/{$resource}/{$id}", $expand ? ['expand' => $expand] : []);

        if ($response->failed()) {
            Log::warning("Stripe could not find {$id}", ['status' => $response->status(), 'error' => $response->json('error.message')]);

            throw new PaymentFailed('This payment could not be found. Please try again.');
        }

        return $response->json();
    }

    private function id(string $prefix, string $id): void
    {
        if (! preg_match(sprintf(self::ID_PATTERN, $prefix), $id)) {
            throw new PaymentFailed('This payment could not be found. Please try again.');
        }
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl('https://api.stripe.com')
            ->withToken((string) config('payments.stripe.secret_key'))
            ->withHeaders(['Stripe-Version' => (string) config('payments.stripe.api_version', '2024-06-20')])
            ->acceptJson()
            ->asForm()
            ->timeout(30);
    }

    private function ok(Response $response, string $action): Response
    {
        if ($response->failed()) {
            Log::error("Stripe could not {$action}", ['status' => $response->status(), 'error' => $response->json('error')]);

            throw new PaymentFailed('The card payment could not be started. Please try again or contact us.');
        }

        return $response;
    }
}
