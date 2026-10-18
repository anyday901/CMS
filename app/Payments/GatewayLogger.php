<?php

namespace App\Payments;

use App\Models\GatewayLog;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Writes the gateway log: what a payment gateway sent to one of its routes,
 * whether it was accepted, and why not. Secrets and card data are taken out
 * before anything is kept. The LogGatewayCallback middleware calls it, so
 * gateways themselves never write to the log.
 */
class GatewayLogger
{
    public const REDACTED = '[redacted]';

    /** The longest payload kept, in bytes of JSON. */
    private const MAX_PAYLOAD = 65536;

    /** Values are never kept for keys containing one of these. */
    private const SECRET_PARTS = [
        'secret', 'password', 'token', 'signature', 'api_key', 'apikey', 'authorization', 'nonce', 'cvc', 'cvv',
        'card_number', 'cardnumber', 'account_number', 'routing_number', 'iban', 'fingerprint', 'source_id',
    ];

    /** Values are never kept for keys that are exactly one of these: card details inside a card object. */
    private const SECRET_KEYS = ['number', 'pan', 'exp_month', 'exp_year', 'expiry', 'expiration', 'expiration_date', 'bin', 'security_code', 'sort_code'];

    public function record(string $gateway, Request $request, Response $response): GatewayLog
    {
        [$outcome, $message] = $this->outcome($request, $response);
        $payload = $this->redact($this->payload($request));

        return GatewayLog::create([
            'gateway' => $gateway,
            'endpoint' => Str::limit($request->path(), 250, ''),
            'event' => $this->event($payload),
            'outcome' => $outcome,
            'response_status' => $response->getStatusCode(),
            'message' => $message === null ? null : Str::limit($this->scrub($message), 1000),
            'payload' => $this->encode($payload),
            'ip_address' => $request->ip(),
            'client_id' => $request->user('client')?->getKey(),
            'invoice_id' => $this->invoiceId($request),
        ]);
    }

    /**
     * The payload with secrets and card data replaced by [redacted].
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSecret($key) && $value !== null && $value !== '') {
                $data[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            } elseif (is_string($value)) {
                $data[$key] = $this->scrub($value);
            }
        }

        return $data;
    }

    /**
     * Accepted, rejected (refused or turned away, a 4xx answer or a redirect
     * with errors) or error (it failed on our side), with the reason.
     *
     * @return array{0: string, 1: ?string}
     */
    private function outcome(Request $request, Response $response): array
    {
        $status = $response->getStatusCode();
        $exception = property_exists($response, 'exception') ? $response->exception : null;

        if ($status >= 500) {
            return [GatewayLog::ERROR, $exception instanceof Throwable ? class_basename($exception).': '.$exception->getMessage() : "The app answered {$status}."];
        }

        if ($status >= 400) {
            $json = json_decode((string) $response->getContent(), true);
            $message = is_array($json) && is_string($json['message'] ?? null) ? $json['message'] : $exception?->getMessage();

            return [GatewayLog::REJECTED, filled($message) ? $message : "The app answered {$status}."];
        }

        // Only errors flashed by this request, not ones left from the page before.
        $flashed = $request->hasSession() && in_array('errors', (array) $request->session()->get('_flash.new', []), true);
        $errors = $flashed ? $request->session()->get('errors') : null;
        $first = match (true) {
            $errors instanceof ViewErrorBag, $errors instanceof MessageBag => $errors->first(),
            default => null,
        };

        return filled($first) ? [GatewayLog::REJECTED, $first] : [GatewayLog::ACCEPTED, null];
    }

    /** @return array<mixed> */
    private function payload(Request $request): array
    {
        $input = $request->input();

        if ($input === [] && $request->getContent() !== '') {
            return ['_body' => Str::limit($request->getContent(), 2000)];
        }

        return $input;
    }

    /** @param  array<mixed>  $payload */
    private function event(array $payload): ?string
    {
        foreach (['event_type', 'type'] as $key) {
            if (is_string($payload[$key] ?? null) && $payload[$key] !== '') {
                return Str::limit($payload[$key], 250, '');
            }
        }

        return null;
    }

    /** @param  array<mixed>  $payload */
    private function encode(array $payload): ?string
    {
        if ($payload === []) {
            return null;
        }

        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return strlen($json) > self::MAX_PAYLOAD ? mb_strcut($json, 0, self::MAX_PAYLOAD).' … (cut at 64 KB)' : $json;
    }

    private function isSecret(string $key): bool
    {
        $key = strtolower($key);

        return in_array($key, self::SECRET_KEYS, true) || Str::contains($key, self::SECRET_PARTS);
    }

    /** Takes card numbers (13 to 19 digits that pass the Luhn check) out of free text. */
    private function scrub(string $text): string
    {
        return (string) preg_replace_callback('/\b\d(?:[ -]?\d){12,18}\b/', function (array $match) {
            $digits = preg_replace('/\D/', '', $match[0]);

            return self::luhn((string) $digits) ? self::REDACTED : $match[0];
        }, $text);
    }

    private static function luhn(string $digits): bool
    {
        $sum = 0;
        foreach (array_reverse(str_split($digits)) as $i => $digit) {
            $value = (int) $digit * ($i % 2 === 1 ? 2 : 1);
            $sum += $value > 9 ? $value - 9 : $value;
        }

        return $sum % 10 === 0;
    }

    /** The invoice in the route, such as the one a Stripe return is for. */
    private function invoiceId(Request $request): ?int
    {
        $id = $request->route('invoice');

        return is_numeric($id) && Invoice::whereKey((int) $id)->exists() ? (int) $id : null;
    }
}
