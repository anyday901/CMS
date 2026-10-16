# Payment gateways

A payment gateway is a way for clients to pay an invoice from the portal. CMS ships with two: PayPal (with Venmo as a funding option inside the same checkout) and Cash App Pay through Square. In v1, clients pay each invoice by hand; there is no automatic charging or saved payment method.

The billing core never talks to a provider. A gateway renders its own checkout widget, takes the payment through its provider's API, and hands the result to `App\Billing\PaymentRecorder`, which records it, applies it to the invoice and fires the `invoice.paid` event when nothing is left to pay.

The code is in `app/Payments`:

| File | What it is |
|---|---|
| `Gateway.php` | The interface every gateway implements |
| `RefundsPayments.php` | An optional interface for gateways that can send money back through their API |
| `GatewayRefund.php` | What a refund request returns: the provider's refund id and a status |
| `PaymentFailed.php` | The exception a gateway throws when a payment or refund doesn't go through. Its message is shown to clients (or staff, for refunds) |
| `GatewayRegistry.php` | Reads `config/payments.php` and returns the enabled gateways |
| `Gateways/PayPal.php`, `Gateways/CashAppPay.php` | The two built-in gateways |
| `Gateways/SquareWebhook.php` | Square's refund and dispute notifications for Cash App Pay |

## The `Gateway` interface

```php
namespace App\Payments;

use App\Models\Invoice;

interface Gateway
{
    /** Stored on transactions, e.g. "paypal". */
    public function key(): string;

    public function label(): string;

    /** True when credentials are configured. */
    public function isEnabled(): bool;

    /** Blade view for the checkout widget, rendered with the invoice. */
    public function view(): string;

    /** Extra data for the view. */
    public function viewData(Invoice $invoice): array;
}
```

- **`key()`** is saved in the `gateway` column of every transaction the gateway records, and is how refunds and the nightly refund check find the gateway again. Keep it short and never change it once payments exist.
- **`label()`** is the name for people, such as "PayPal or Venmo".
- **`isEnabled()`** returns true once the gateway's credentials are set. A gateway that isn't enabled isn't shown on invoices, can't be found for refunds, and its routes should answer 404 (the built-in controllers check it with `abort_unless($gateway->isEnabled(), 404)`).
- **`view()`** names the Blade view of the checkout widget, such as `payments.paypal` (`resources/views/payments/paypal.blade.php`).
- **`viewData()`** returns the variables that view needs besides the invoice, such as the provider's SDK address and public keys. Never put secrets here: the view is sent to the browser.

## Registering and configuring a gateway

Gateways are listed in `config/payments.php`, in the order they appear on an invoice. Each gateway keeps its settings in the same file, read from `.env`:

```php
return [
    'gateways' => [
        PayPal::class,
        CashAppPay::class,
        // App\Payments\Gateways\Acme::class,
    ],

    'paypal' => [
        'mode' => env('PAYPAL_MODE', 'sandbox'),
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'secret' => env('PAYPAL_SECRET'),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
        'venmo' => (bool) env('PAYPAL_VENMO', true),
    ],

    'square' => [
        'environment' => env('SQUARE_ENVIRONMENT', 'sandbox'),
        'application_id' => env('SQUARE_APPLICATION_ID'),
        'access_token' => env('SQUARE_ACCESS_TOKEN'),
        'location_id' => env('SQUARE_LOCATION_ID'),
        'version' => env('SQUARE_VERSION', '2024-12-18'),
        'webhook_signature_key' => env('SQUARE_WEBHOOK_SIGNATURE_KEY'),
        'webhook_url' => env('SQUARE_WEBHOOK_URL'),
    ],
];
```

Add your gateway's class to `gateways` and its settings under a key of its own, then read them with `config('payments.acme.api_key')`. `isEnabled()` is normally just a check that the required ones are filled in:

```php
public function isEnabled(): bool
{
    return filled(config('payments.acme.api_key')) && filled(config('payments.acme.webhook_secret'));
}
```

`App\Payments\GatewayRegistry` resolves each listed class through the service container, so a gateway can ask for what it needs in its constructor (the built-in ones take `PaymentRecorder`, and PayPal also `DisputeRecorder`). `enabled()` returns the enabled gateways in order, and `find($key)` the enabled one with that key.

A gateway shipped as a Composer package can add itself to the list from its service provider, since the registry reads the config each time:

```php
config(['payments.gateways' => [...config('payments.gateways', []), AcmeGateway::class]]);
```

## How a payment flows

Taking PayPal as the example:

1. The client opens an unpaid invoice in the portal. `Portal\InvoiceController::show` passes `GatewayRegistry::enabled()` to `portal/invoices/show.blade.php`, which includes each gateway's view with the invoice and its `viewData()`:

    ```blade
    @include($gateway->view(), ['invoice' => $invoice] + $gateway->viewData($invoice))
    ```

2. The widget (PayPal's JavaScript buttons) asks the app to start a payment: `POST /portal/invoices/{invoice}/paypal/order` calls `PayPal::createOrder()`, which creates a PayPal order for the invoice's balance and returns its id.
3. The client approves the payment with PayPal, and the widget posts the order id back to `POST /portal/invoices/{invoice}/paypal/capture`, which calls `PayPal::captureOrder()`.
4. `captureOrder()` captures the payment, checks it belongs to this invoice and is in the invoice's currency, and records it with `PaymentRecorder::record()`.
5. The controller answers with a redirect to the invoice, and the widget follows it. On a `PaymentFailed`, it answers 422 with the message, and the widget shows it in the invoice page's `#payment-error` box.

Cash App Pay is the same shape with one call: Square's Web Payments SDK gives the browser a one-time token, the widget posts it to `POST /portal/invoices/{invoice}/cashapp`, and `CashAppPay::charge()` charges it and records the payment.

The methods that take a payment (`createOrder`, `captureOrder`, `charge`) aren't part of the interface, since every provider works differently. Your gateway adds its own methods, a controller to call them, and routes. The built-in routes are in `routes/web.php`, inside the portal's invoice group, so they need a signed-in client (or a contact allowed to see billing) and are rate limited:

```php
Route::middleware('contact.can:billing')->group(function () {
    Route::prefix('invoices/{invoice}')->group(function () {
        Route::middleware('throttle:20,1')->group(function () {
            Route::post('paypal/order', [Portal\PaymentController::class, 'paypalOrder'])->name('pay.paypal.order');
            Route::post('paypal/capture', [Portal\PaymentController::class, 'paypalCapture'])->name('pay.paypal.capture');
            Route::post('cashapp', [Portal\PaymentController::class, 'cashApp'])->name('pay.cashapp');
        });
    });
});
```

`Portal\PaymentController::payable()` shows the checks to copy: the invoice must be the signed-in client's own, unpaid, with a balance over zero.

### Recording the payment

```php
public function record(
    Invoice $invoice,
    int $amount,          // in cents
    string $gateway,      // your key()
    ?string $reference = null,  // the provider's payment id
    int $fee = 0,         // the provider's fee, in cents, for reports
    ?string $method = null,     // e.g. "venmo", when one gateway takes several methods
): Transaction
```

- **Amounts are integers in cents.** Use `App\Support\Money::parse('15.00')` to turn a provider's decimal string into 1500, and `Money::toInput(1500)` for the other way.
- **Charge the invoice's current balance**, `$invoice->balance()`, not its total: it may be partly paid or have credit applied.
- **Always pass the provider's payment id as `$reference`.** A second call with the same gateway and reference returns the transaction already recorded instead of a new one, even when two callbacks arrive at the same moment. This is what makes it safe to record a payment from both the browser's return and the provider's webhook.
- Anything over the balance goes to the client's credit balance.
- When the invoice has nothing left to pay, it is marked paid, which moves its services' next due dates on, activates its pending services (unless the order is held for review) and unsuspends services suspended for being overdue once nothing else is overdue. Then `App\Events\InvoicePaid` is dispatched, which sends the receipt, applies a paid upgrade and fires the `invoice.paid` hook event.
- `record()` throws `InvalidArgumentException` when the amount isn't positive or the invoice isn't unpaid any more (already paid another way, or cancelled). By then the money has moved, so log it as critical for staff and throw a `PaymentFailed` with a message for the client, as both built-in gateways do:

    ```php
    try {
        return $this->payments->record($invoice, $amount, $this->key(), $payment['id'], $fee);
    } catch (InvalidArgumentException $e) {
        Log::critical('Acme payment received but not applied', ['invoice' => $invoice->id, 'payment' => $payment['id'], 'reason' => $e->getMessage()]);

        throw new PaymentFailed('We received your payment but could not apply it to this invoice automatically. We will sort it out and contact you.');
    }
    ```

Before recording, check what the provider tells you against the invoice: the payment's status, that it names this invoice (PayPal's `custom_id`, Square's `reference_id`), and that its currency is the invoice's currency.

Make requests that move money idempotent with the provider's own mechanism, so a retried request can't charge twice: PayPal's capture sends a `PayPal-Request-Id` header of `capture-{orderId}`, and Square's charge an `idempotency_key` made from the invoice id and the token.

The transaction's method shows on invoices through `Transaction::methodLabel()`, which knows the built-in keys and otherwise shows the key with its first letter in capitals (`acme_pay` becomes "Acme pay").

## Confirming payments with webhooks

Some payments aren't finished when the client returns: PayPal can hold a capture as pending, and `captureOrder()` then returns null and the client sees a "still processing" message. The provider's webhook records it when it clears.

Webhook routes go at the top of `routes/web.php`, outside the portal, and `bootstrap/app.php` exempts `webhooks/*` from CSRF checks:

```php
Route::post('webhooks/paypal', [WebhookController::class, 'paypal'])->name('webhooks.paypal');
Route::post('webhooks/square', [WebhookController::class, 'square'])->name('webhooks.square');
```

`App\Http\Controllers\WebhookController` shows the pattern:

1. **Verify the request first**, and answer 400 to anything that doesn't verify. PayPal's `verifyWebhook()` asks PayPal's verify-webhook-signature API with the configured `webhook_id`; `SquareWebhook::verify()` checks Square's HMAC-SHA256 of the notification URL followed by the raw body, compared with `hash_equals()`. Use the raw body (`$request->getContent()`) for signatures, not re-encoded JSON.
2. **Handle the event types you know** and ignore the rest. For PayPal, `PAYMENT.CAPTURE.COMPLETED` goes to `captureCompleted()`, which finds the invoice from the capture's `custom_id` and records it with the same `record()` call, so a capture the browser already recorded isn't recorded twice.
3. **Answer 200** once it's handled, including when recording failed with a `PaymentFailed` that was already logged, so the provider stops retrying something that can't succeed.

## Refunds

A gateway that implements `App\Payments\RefundsPayments` lets staff refund a payment back to the payer from the admin invoice page (the **gateway** refund mode). Without it, staff can still record a refund made elsewhere, or refund to credit.

```php
namespace App\Payments;

use App\Models\Transaction;

interface RefundsPayments
{
    /**
     * Refunds part or all of a payment.
     *
     * @throws PaymentFailed when the gateway refuses the refund.
     */
    public function refund(Transaction $payment, int $amount, string $idempotencyKey): GatewayRefund;

    /** Looks up a refund the gateway reported as pending. */
    public function refundStatus(string $refundId): GatewayRefund;
}
```

- `refund()` is called by `App\Billing\RefundService` with the payment transaction (its `gateway_reference` is the provider's payment id, and `currency` its currency), the amount in cents, and an idempotency key that is the same for a retry of the same refund and different for a second partial refund. Pass the key to the provider.
- Return `new GatewayRefund($refundId, $status)`, where the status is `GatewayRefund::COMPLETED`, `GatewayRefund::PENDING` or `GatewayRefund::FAILED`. Map the provider's own statuses onto these, as both built-in gateways do in `refundResult()`.
- Throw `PaymentFailed` when the provider refuses. Its message is shown to staff.
- A pending refund is recorded straight away and checked again by the nightly `billing:run`: `App\Billing\RefundReconciler::run()` calls `refundStatus()` on each pending refund. A completed one is marked done; a failed one is removed, so the payment counts again and can be refunded again.

Refunds and disputes can also start at the provider. `SquareWebhook` handles both:

- A refund issued from the provider's dashboard is recorded with `RefundService::recordFromGateway($payment, $amount, new GatewayRefund($id, $status))`. A refund already recorded under the same id is left as it is.
- A webhook that settles a pending refund calls `RefundReconciler::settle($refund, $status)`.
- A dispute or chargeback is passed to `App\Billing\DisputeRecorder::update($payment, $disputeId, $state, $amount, $reason)`. It records the state on the payment, emails staff with billing access, and records a refund of the disputed amount when the state is `lost` or `accepted` (`DisputeRecorder::LOST_STATES`). A repeated webhook with the same state does nothing.

Find the payment a webhook is about by your gateway key and the provider's payment id, and leave alone any the app didn't record:

```php
$payment = Transaction::where('gateway', $this->key())
    ->where('gateway_reference', $paymentId)
    ->where('amount', '>', 0)
    ->first();
```

## Writing the checkout view

The view is included inside the invoice page's **Pay** box, and gets `$invoice` plus your `viewData()`. Load the provider's script, render its button, and post to your routes with the page's CSRF token. Look at `resources/views/payments/cashapp.blade.php`:

```blade
<div id="cash-app-pay"></div>
<script src="{{ $sdkUrl }}"></script>
<script>
(async () => {
    const csrf = '{{ csrf_token() }}';
    // ... when the provider hands back a token:
    const res = await fetch(@json(route('portal.pay.cashapp', $invoice)), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ token: tokenResult.token }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) return showError(data.message);
    window.location = data.redirect;
})();
</script>
```

Show errors in the page's `#payment-error` element (remove its `hidden` class and set its text), so every gateway reports problems in the same place. Pass values to JavaScript with `@json()`, never by echoing them into a script.

## Testing with `Http::fake`

Gateways call their provider through Laravel's HTTP client, so tests fake the provider and assert what was sent. Set the gateway's config in `setUp()`, make an invoice, and act as its client:

```php
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CashAppPayTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.square' => [
            'environment' => 'sandbox', 'application_id' => 'sandbox-app', 'access_token' => 'sq-token',
            'location_id' => 'LOC1', 'version' => '2024-12-18',
        ]]);

        $this->client = Client::factory()->create();
        $this->invoice = Invoice::create(['client_id' => $this->client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $this->invoice->items()->create(['description' => 'Hosting', 'amount' => 2500]);
        $this->invoice->recalculate();
        $this->actingAs($this->client, 'client');
    }

    public function test_successful_charge_pays_the_invoice(): void
    {
        Http::fake(['connect.squareupsandbox.com/v2/payments' => Http::response(['payment' => [
            'id' => 'SQ-PAY-1',
            'status' => 'COMPLETED',
            'amount_money' => ['amount' => 2500, 'currency' => 'USD'],
            'processing_fee' => [['amount_money' => ['amount' => 70, 'currency' => 'USD']]],
        ]])]);

        $this->postJson("/portal/invoices/{$this->invoice->id}/cashapp", ['token' => 'cnon:abc'])->assertOk();

        Http::assertSent(fn ($r) => $r['amount_money']['amount'] === 2500
            && $r['source_id'] === 'cnon:abc'
            && $r->hasHeader('Authorization', 'Bearer sq-token'));

        $this->assertSame(InvoiceStatus::Paid, $this->invoice->refresh()->status);
        $this->assertSame('SQ-PAY-1', $this->invoice->transactions->sole()->gateway_reference);
    }
}
```

Cover at least:

- the widget shows on an unpaid invoice once configured, and not without credentials;
- a successful payment marks the invoice paid with the right amount, fee and reference;
- a declined payment leaves the invoice unpaid and answers 422 with a message;
- a payment for another invoice, or in another currency, isn't applied;
- repeating a callback or webhook records one transaction (with `Http::recorded()` you can check a retried request reused its idempotency key);
- a webhook with a bad signature gets 400 and changes nothing;
- refunds: completed, pending (then settled by `RefundReconciler`), and refused.

`tests/Feature/Payments` has the full tests for PayPal, its disputes, and Cash App Pay, and `tests/Feature/Billing/RefundTest.php` the refund flows. Test against the provider's sandbox before a live payment.
