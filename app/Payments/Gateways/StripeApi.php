<?php

namespace App\Payments\Gateways;

use App\Payments\GatewayRefund;
use App\Payments\PaymentFailed;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talking to Stripe's REST API: requests, checked ids, error handling,
 * webhook signatures and amount conversion, shared by the Stripe gateway
 * and its saved cards.
 *
 * Amounts: this app keeps every currency in hundredths. Stripe wants whole
 * units for zero-decimal currencies such as JPY and thousandths for
 * three-decimal ones such as KWD, so toStripe() and fromStripe() convert.
 */
class StripeApi
{
    /** Currencies Stripe takes in whole units. */
    public const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** Currencies Stripe takes in thousandths. */
    public const THREE_DECIMAL = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    /** Stripe ids look like "pi_3Abc..."; anything else could change the request path. */
    private const ID_PATTERN = '/^(%s)_[A-Za-z0-9]{1,250}$/';

    private const DECLINED = 'Your card was declined. Please try another card or another way to pay.';

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
     * Every refund of a payment, as Stripe reports them.
     *
     * @return list<array<string, mixed>>
     */
    public function refundsFor(string $intentId): array
    {
        $this->id('pi', $intentId);
        $response = $this->request()->get('/v1/refunds', ['payment_intent' => $intentId, 'limit' => 100]);

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

    /** Stripe's fee from the charge's balance transaction, when it's in the invoice's currency. */
    public static function fee(array $intent, string $currency): int
    {
        $balance = $intent['latest_charge']['balance_transaction'] ?? null;

        if (! is_array($balance) || strtoupper((string) ($balance['currency'] ?? '')) !== $currency) {
            return 0;
        }

        return self::fromStripe((int) ($balance['fee'] ?? 0), $currency);
    }

    /** A decline or error message from Stripe that is fine to show the client. */
    public function declineMessage(?array $error, string $default = self::DECLINED): string
    {
        return match (true) {
            ($error['code'] ?? null) === 'authentication_required' => 'Your bank asked to confirm this payment. Please pay the invoice in the client area.',
            // Card errors are written for the cardholder; other errors could show how the app talks to Stripe.
            ($error['type'] ?? null) === 'card_error' && filled($error['message'] ?? null) => $error['message'],
            default => $default,
        };
    }

    /** @param  list<string>  $expand */
    public function retrieve(string $resource, string $prefix, string $id, array $expand = []): array
    {
        $this->id($prefix, $id);
        $response = $this->request()->get("/v1/{$resource}/{$id}", $expand ? ['expand' => $expand] : []);

        if ($response->failed()) {
            Log::warning("Stripe could not find {$id}", ['status' => $response->status(), 'error' => $response->json('error.message')]);

            throw new PaymentFailed('This payment could not be found. Please try again.');
        }

        return $response->json();
    }

    public function id(string $prefix, string $id): void
    {
        if (! preg_match(sprintf(self::ID_PATTERN, $prefix), $id)) {
            throw new PaymentFailed('This payment could not be found. Please try again.');
        }
    }

    public function request(): PendingRequest
    {
        return Http::baseUrl('https://api.stripe.com')
            ->withToken((string) config('payments.stripe.secret_key'))
            ->withHeaders(['Stripe-Version' => (string) config('payments.stripe.api_version', '2024-06-20')])
            ->acceptJson()
            ->asForm()
            ->timeout(30);
    }

    public function ok(Response $response, string $action): Response
    {
        if ($response->failed()) {
            Log::error("Stripe could not {$action}", ['status' => $response->status(), 'error' => $response->json('error')]);

            throw new PaymentFailed('The card payment could not be started. Please try again or contact us.');
        }

        return $response;
    }
}
