<?php

namespace App\Payments\Gateways;

use App\Billing\PaymentRecorder;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Payments\Gateway;
use App\Payments\GatewayRefund;
use App\Payments\PaymentFailed;
use App\Payments\RefundsPayments;
use App\Support\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * PayPal Checkout (Orders API v2) with the PayPal JS SDK buttons. Venmo is a
 * funding option inside the same checkout, so it is handled here too.
 */
class PayPal implements Gateway, RefundsPayments
{
    /** PayPal order, capture and refund ids. Anything else could change the request path. */
    private const ID_PATTERN = '/^[A-Za-z0-9]{1,64}$/';

    public function __construct(private PaymentRecorder $payments) {}

    public function key(): string
    {
        return 'paypal';
    }

    public function label(): string
    {
        return config('payments.paypal.venmo') ? 'PayPal or Venmo' : 'PayPal';
    }

    public function isEnabled(): bool
    {
        return filled(config('payments.paypal.client_id')) && filled(config('payments.paypal.secret'));
    }

    public function view(): string
    {
        return 'payments.paypal';
    }

    public function viewData(Invoice $invoice): array
    {
        $query = http_build_query(array_filter([
            'client-id' => config('payments.paypal.client_id'),
            'currency' => $invoice->currency,
            'intent' => 'capture',
            'components' => 'buttons',
            'enable-funding' => config('payments.paypal.venmo') ? 'venmo' : null,
            'disable-funding' => 'paylater,card',
        ]));

        return ['sdkUrl' => "https://www.paypal.com/sdk/js?{$query}"];
    }

    /** Creates a PayPal order for the invoice's current balance and returns its id. */
    public function createOrder(Invoice $invoice): string
    {
        $response = $this->api()->post('/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => (string) $invoice->id,
                'custom_id' => (string) $invoice->id,
                'description' => config('app.name')." invoice #{$invoice->number}",
                'amount' => [
                    'currency_code' => $invoice->currency,
                    'value' => Money::toInput($invoice->balance()),
                ],
            ]],
            'payment_source' => [
                'paypal' => ['experience_context' => [
                    'shipping_preference' => 'NO_SHIPPING',
                    'user_action' => 'PAY_NOW',
                    'brand_name' => config('app.name'),
                ]],
            ],
        ]);

        return $this->ok($response, 'create order')->json('id');
    }

    /**
     * Captures an approved order and records the payment. Returns null when
     * PayPal holds the capture as pending; the webhook records it later.
     */
    public function captureOrder(Invoice $invoice, string $orderId): ?Transaction
    {
        // The id goes into the request path, so anything but a plain PayPal
        // order id could point this authenticated call at another endpoint.
        if (! preg_match(self::ID_PATTERN, $orderId)) {
            throw new PaymentFailed('This payment could not be found. Please try again.');
        }

        $response = $this->api()
            ->withHeaders(['PayPal-Request-Id' => "capture-{$orderId}"])
            ->post("/v2/checkout/orders/{$orderId}/capture", (object) []);

        $order = $this->ok($response, 'capture order')->json();
        $capture = $order['purchase_units'][0]['payments']['captures'][0] ?? null;

        if ($capture === null || ($capture['custom_id'] ?? null) !== (string) $invoice->id) {
            Log::warning('PayPal capture does not match invoice', ['invoice' => $invoice->id, 'order' => $orderId]);
            throw new PaymentFailed('This payment does not match the invoice. Please contact us.');
        }

        if ($capture['status'] === 'PENDING') {
            return null;
        }

        if ($capture['status'] !== 'COMPLETED') {
            throw new PaymentFailed('PayPal declined this payment. Please try another payment method.');
        }

        $method = array_key_exists('venmo', $order['payment_source'] ?? []) ? 'venmo' : 'paypal';

        return $this->record($invoice, $capture, $method);
    }

    /** Handles a verified PAYMENT.CAPTURE.COMPLETED webhook resource. */
    public function captureCompleted(array $capture): ?Transaction
    {
        $invoice = Invoice::find($capture['custom_id'] ?? null);

        if ($invoice === null) {
            Log::warning('PayPal webhook for unknown invoice', ['capture' => $capture['id'] ?? null]);

            return null;
        }

        return $this->record($invoice, $capture, null);
    }

    public function refund(Transaction $payment, int $amount, string $idempotencyKey): GatewayRefund
    {
        $captureId = (string) $payment->gateway_reference;

        if (! preg_match(self::ID_PATTERN, $captureId)) {
            throw new PaymentFailed('This payment has no PayPal capture id to refund.');
        }

        $response = $this->api()
            ->withHeaders(['PayPal-Request-Id' => $idempotencyKey])
            ->post("/v2/payments/captures/{$captureId}/refund", [
                'amount' => ['value' => Money::toInput($amount), 'currency_code' => $payment->currency],
            ]);

        if ($response->failed() || ! in_array($response->json('status'), ['COMPLETED', 'PENDING'], true)) {
            Log::error('PayPal refund failed', ['transaction' => $payment->id, 'status' => $response->status(), 'body' => $response->json()]);

            throw new PaymentFailed('PayPal refused the refund: '.($response->json('details.0.description') ?? $response->json('message') ?? 'unknown error').'.');
        }

        return $this->refundResult($response->json());
    }

    public function refundStatus(string $refundId): GatewayRefund
    {
        if (! preg_match(self::ID_PATTERN, $refundId)) {
            throw new PaymentFailed('Not a PayPal refund id.');
        }

        $response = $this->api()->get("/v2/payments/refunds/{$refundId}");

        if ($response->failed()) {
            throw new PaymentFailed("PayPal could not look up refund {$refundId}.");
        }

        return $this->refundResult($response->json());
    }

    private function refundResult(array $refund): GatewayRefund
    {
        return new GatewayRefund($refund['id'], match ($refund['status'] ?? null) {
            'COMPLETED' => GatewayRefund::COMPLETED,
            'PENDING' => GatewayRefund::PENDING,
            default => GatewayRefund::FAILED, // FAILED or CANCELLED
        });
    }

    public function verifyWebhook(array $headers, array $event): bool
    {
        $webhookId = config('payments.paypal.webhook_id');

        if (blank($webhookId)) {
            return false;
        }

        $response = $this->api()->post('/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $headers['paypal-auth-algo'] ?? null,
            'cert_url' => $headers['paypal-cert-url'] ?? null,
            'transmission_id' => $headers['paypal-transmission-id'] ?? null,
            'transmission_sig' => $headers['paypal-transmission-sig'] ?? null,
            'transmission_time' => $headers['paypal-transmission-time'] ?? null,
            'webhook_id' => $webhookId,
            'webhook_event' => $event,
        ]);

        return $response->successful() && $response->json('verification_status') === 'SUCCESS';
    }

    private function record(Invoice $invoice, array $capture, ?string $method): Transaction
    {
        if (($capture['amount']['currency_code'] ?? null) !== $invoice->currency) {
            throw new PaymentFailed('This payment was made in the wrong currency. Please contact us.');
        }

        $fee = $capture['seller_receivable_breakdown']['paypal_fee']['value'] ?? '0';

        try {
            return $this->payments->record(
                $invoice,
                Money::parse($capture['amount']['value']),
                $this->key(),
                $capture['id'],
                Money::parse($fee),
                $method,
            );
        } catch (InvalidArgumentException $e) {
            // The money has moved but the invoice can't take it (already paid
            // or cancelled). Staff need to apply or refund it by hand.
            Log::critical('PayPal payment received but not applied', ['invoice' => $invoice->id, 'capture' => $capture['id'], 'reason' => $e->getMessage()]);

            throw new PaymentFailed('We received your payment but could not apply it to this invoice automatically. We will sort it out and contact you.');
        }
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }

    private function accessToken(): string
    {
        $cacheKey = 'paypal-token-'.hash('sha256', config('payments.paypal.client_id').'|'.config('payments.paypal.mode'));

        return Cache::remember($cacheKey, now()->addMinutes(30), function () {
            $response = Http::baseUrl($this->baseUrl())
                ->withBasicAuth(config('payments.paypal.client_id'), config('payments.paypal.secret'))
                ->asForm()
                ->post('/v1/oauth2/token', ['grant_type' => 'client_credentials']);

            return $this->ok($response, 'get access token')->json('access_token');
        });
    }

    private function baseUrl(): string
    {
        return config('payments.paypal.mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    private function ok(Response $response, string $action): Response
    {
        if ($response->failed()) {
            Log::error("PayPal could not {$action}", ['status' => $response->status(), 'body' => $response->json()]);

            throw new PaymentFailed('PayPal could not complete the payment. Please try again or contact us.');
        }

        return $response;
    }
}
