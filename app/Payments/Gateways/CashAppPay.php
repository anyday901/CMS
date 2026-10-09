<?php

namespace App\Payments\Gateways;

use App\Billing\PaymentRecorder;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Payments\Gateway;
use App\Payments\PaymentFailed;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Cash App Pay through Square's Web Payments SDK. The browser gets a
 * one-time token from Cash App, and the server charges it through Square's
 * Payments API, so card or account details never touch this app.
 */
class CashAppPay implements Gateway
{
    public function __construct(private PaymentRecorder $payments) {}

    public function key(): string
    {
        return 'cashapp';
    }

    public function label(): string
    {
        return 'Cash App Pay';
    }

    public function isEnabled(): bool
    {
        return filled(config('payments.square.application_id'))
            && filled(config('payments.square.access_token'))
            && filled(config('payments.square.location_id'));
    }

    public function view(): string
    {
        return 'payments.cashapp';
    }

    public function viewData(Invoice $invoice): array
    {
        return [
            'sdkUrl' => $this->production()
                ? 'https://web.squarecdn.com/v1/square.js'
                : 'https://sandbox.web.squarecdn.com/v1/square.js',
            'applicationId' => config('payments.square.application_id'),
            'locationId' => config('payments.square.location_id'),
        ];
    }

    /** Charges a Cash App Pay token for the invoice's current balance. */
    public function charge(Invoice $invoice, string $token): Transaction
    {
        $amount = $invoice->balance();

        $response = Http::baseUrl($this->production() ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com')
            ->withToken(config('payments.square.access_token'))
            ->withHeaders(['Square-Version' => config('payments.square.version')])
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->post('/v2/payments', [
                // Same token and invoice always map to the same key, so a
                // retried request cannot charge twice.
                'idempotency_key' => substr(hash('sha256', "{$invoice->id}|{$token}"), 0, 45),
                'source_id' => $token,
                'amount_money' => ['amount' => $amount, 'currency' => $invoice->currency],
                'location_id' => config('payments.square.location_id'),
                'reference_id' => (string) $invoice->id,
                'note' => config('app.name')." invoice #{$invoice->number}",
                'autocomplete' => true,
            ]);

        $payment = $response->json('payment');

        if ($response->failed() || ($payment['status'] ?? null) !== 'COMPLETED') {
            Log::warning('Cash App Pay charge failed', ['invoice' => $invoice->id, 'status' => $response->status(), 'body' => $response->json()]);

            throw new PaymentFailed('Cash App could not complete the payment. Please try again or choose another way to pay.');
        }

        $fee = collect($payment['processing_fee'] ?? [])->sum(fn ($f) => $f['amount_money']['amount'] ?? 0);

        try {
            return $this->payments->record(
                $invoice,
                (int) $payment['amount_money']['amount'],
                $this->key(),
                $payment['id'],
                (int) $fee,
            );
        } catch (InvalidArgumentException $e) {
            // The money has moved but the invoice can't take it (already paid
            // or cancelled). Staff need to apply or refund it by hand.
            Log::critical('Cash App payment received but not applied', ['invoice' => $invoice->id, 'payment' => $payment['id'], 'reason' => $e->getMessage()]);

            throw new PaymentFailed('We received your payment but could not apply it to this invoice automatically. We will sort it out and contact you.');
        }
    }

    private function production(): bool
    {
        return config('payments.square.environment') === 'production';
    }
}
