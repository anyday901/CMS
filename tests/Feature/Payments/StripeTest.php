<?php

namespace Tests\Feature\Payments;

use App\Billing\PaymentRecorder;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;
use App\Payments\Gateways\Stripe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StripeTest extends TestCase
{
    use RefreshDatabase;

    private const PAYMENT_INTENTS_URL = 'https://api.stripe.com/v1/payment_intents';

    private const SECRET = 'whsec_test123';

    private const INTENT = 'pi_123';

    private Client $client;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.stripe' => [
            'publishable_key' => 'pk_test_abc', 'secret_key' => 'sk_test_abc', 'webhook_secret' => self::SECRET,
            'webhook_tolerance' => 300, 'api_version' => '2024-06-20',
        ]]);

        $this->client = Client::factory()->create();
        $this->invoice = $this->invoiceFor($this->client, 'USD', 2500);
    }

    private function invoiceFor(Client $client, string $currency, int $amount): Invoice
    {
        $invoice = Invoice::create(['client_id' => $client->id, 'currency' => $currency, 'issue_date' => today(), 'due_date' => today()]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => $amount]);
        $invoice->recalculate();

        return $invoice;
    }

    /** A PaymentIntent as Stripe returns it, expanded. */
    private function intent(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => self::INTENT, 'object' => 'payment_intent', 'status' => 'succeeded',
            'amount' => 2500, 'amount_received' => 2500, 'currency' => 'usd',
            'customer' => null, 'setup_future_usage' => null,
            'metadata' => ['invoice_id' => (string) $this->invoice->id],
            'payment_method' => ['id' => 'pm_1', 'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 4, 'exp_year' => 2030]],
            'latest_charge' => ['id' => 'ch_1', 'balance_transaction' => ['fee' => 103, 'currency' => 'usd']],
        ], $overrides);
    }

    private function savedCard(): PaymentMethod
    {
        return PaymentMethod::create([
            'client_id' => $this->client->id, 'gateway' => 'stripe', 'gateway_customer_id' => 'cus_1', 'gateway_reference' => 'pm_1',
            'brand' => 'visa', 'last4' => '4242', 'exp_month' => 4, 'exp_year' => 2030, 'is_default' => true,
        ]);
    }

    private function webhook(array $event, ?int $time = null, string $secret = self::SECRET)
    {
        $body = json_encode($event);
        $time ??= time();
        $signature = hash_hmac('sha256', "{$time}.{$body}", $secret);

        return $this->call('POST', '/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$time},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function recordedPayment(): Transaction
    {
        return app(PaymentRecorder::class)->record($this->invoice, 2500, 'stripe', self::INTENT);
    }

    public function test_invoice_page_shows_the_card_form(): void
    {
        $this->actingAs($this->client, 'client');

        $this->get("/portal/invoices/{$this->invoice->id}")->assertOk()
            ->assertSee('https://js.stripe.com/v3/', false)
            ->assertSee('pk_test_abc')
            ->assertSee('Save this card for future payments')
            ->assertDontSee('Pay with a saved card');
    }

    public function test_paying_by_card_records_the_payment_and_fee(): void
    {
        $this->actingAs($this->client, 'client');
        Http::fake([
            'api.stripe.com/v1/payment_intents' => Http::response(['id' => self::INTENT, 'client_secret' => 'pi_123_secret_abc']),
            'api.stripe.com/v1/payment_intents/pi_123*' => Http::response($this->intent()),
        ]);

        $this->postJson("/portal/invoices/{$this->invoice->id}/stripe/intent", ['save' => false])
            ->assertOk()->assertJson(['client_secret' => 'pi_123_secret_abc']);

        Http::assertSent(fn (Request $r) => $r->url() === self::PAYMENT_INTENTS_URL
            && $r['amount'] === 2500 && $r['currency'] === 'usd'
            && $r['metadata']['invoice_id'] === (string) $this->invoice->id
            && ! isset($r['setup_future_usage'], $r['customer'])
            && $r->hasHeader('Authorization', 'Bearer sk_test_abc'));

        $this->postJson("/portal/invoices/{$this->invoice->id}/stripe/confirm", ['payment_intent' => self::INTENT])
            ->assertOk()->assertJson(['redirect' => route('portal.invoices.show', $this->invoice)]);

        $this->assertSame(InvoiceStatus::Paid, $this->invoice->refresh()->status);
        $transaction = $this->invoice->transactions->sole();
        $this->assertSame(['stripe', self::INTENT, 2500, 103], [$transaction->gateway, $transaction->gateway_reference, $transaction->amount, $transaction->fee]);
        $this->assertSame(0, PaymentMethod::count());
    }

    public function test_the_card_is_saved_when_the_client_asks(): void
    {
        $this->actingAs($this->client, 'client');
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
            'api.stripe.com/v1/payment_intents' => Http::response(['id' => self::INTENT, 'client_secret' => 'pi_123_secret_abc']),
            'api.stripe.com/v1/payment_intents/pi_123*' => Http::response($this->intent(['customer' => 'cus_1', 'setup_future_usage' => 'off_session'])),
        ]);

        $this->postJson("/portal/invoices/{$this->invoice->id}/stripe/intent", ['save' => true])->assertOk();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/customers' && $r['email'] === $this->client->email);
        Http::assertSent(fn (Request $r) => $r->url() === self::PAYMENT_INTENTS_URL && $r['customer'] === 'cus_1' && $r['setup_future_usage'] === 'off_session');
        $this->assertSame('cus_1', $this->client->refresh()->gatewayCustomer('stripe'));

        $this->postJson("/portal/invoices/{$this->invoice->id}/stripe/confirm", ['payment_intent' => self::INTENT])->assertOk();

        $card = PaymentMethod::sole();
        $this->assertSame([$this->client->id, 'cus_1', 'pm_1', 'Visa ending 4242', '04/2030', true], [$card->client_id, $card->gateway_customer_id, $card->gateway_reference, $card->label(), $card->expiry(), $card->is_default]);
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->refresh()->status);

        // The next unpaid invoice offers the saved card.
        $next = $this->invoiceFor($this->client, 'USD', 1000);
        $this->get("/portal/invoices/{$next->id}")->assertSee('Pay with a saved card')->assertSee('Visa ending 4242');
    }

    public function test_paying_with_a_saved_card(): void
    {
        $this->actingAs($this->client, 'client');
        $card = $this->savedCard();
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response($this->intent(['customer' => 'cus_1', 'payment_method' => 'pm_1']))]);

        $this->postJson("/portal/invoices/{$this->invoice->id}/stripe/saved", ['payment_method' => $card->id])
            ->assertOk()->assertJson(['redirect' => route('portal.invoices.show', $this->invoice)]);

        Http::assertSent(fn (Request $r) => $r['payment_method'] === 'pm_1' && $r['customer'] === 'cus_1' && $r['confirm'] === 'true' && ! isset($r['off_session']));
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->refresh()->status);
    }

    public function test_a_saved_card_payment_is_not_made_twice(): void
    {
        $this->actingAs($this->client, 'client');
        $card = $this->savedCard();
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response($this->intent(['status' => 'processing']))]);
        $url = "/portal/invoices/{$this->invoice->id}/stripe/saved";

        // While another request holds the invoice, a second click charges nothing.
        $lock = Cache::lock("charge-invoice-{$this->invoice->id}", 120);
        $lock->get();
        $this->postJson($url, ['payment_method' => $card->id])->assertStatus(422);
        Http::assertNothingSent();
        $lock->release();

        // A repeat while Stripe is processing sends the same key, so Stripe returns the same payment.
        $this->postJson($url, ['payment_method' => $card->id])->assertOk();
        $this->postJson($url, ['payment_method' => $card->id])->assertOk();
        $keys = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0] ?? null);
        $this->assertCount(2, $keys);
        $this->assertNotNull($keys[0]);
        $this->assertSame($keys[0], $keys[1]);
    }

    public function test_a_saved_card_payment_the_bank_wants_confirmed_goes_back_to_the_browser(): void
    {
        $this->actingAs($this->client, 'client');
        $card = $this->savedCard();
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response($this->intent(['status' => 'requires_action', 'client_secret' => 'pi_123_secret_abc']))]);

        $this->postJson("/portal/invoices/{$this->invoice->id}/stripe/saved", ['payment_method' => $card->id])
            ->assertOk()->assertJson(['client_secret' => 'pi_123_secret_abc']);
        $this->assertSame(InvoiceStatus::Unpaid, $this->invoice->refresh()->status);
    }

    public function test_another_clients_saved_card_cannot_be_used(): void
    {
        $other = PaymentMethod::create(['client_id' => Client::factory()->create()->id, 'gateway' => 'stripe', 'gateway_customer_id' => 'cus_9', 'gateway_reference' => 'pm_9']);
        $this->actingAs($this->client, 'client');
        Http::fake();

        $this->postJson("/portal/invoices/{$this->invoice->id}/stripe/saved", ['payment_method' => $other->id])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_a_declined_or_mismatched_payment_is_not_recorded(): void
    {
        $this->actingAs($this->client, 'client');
        Http::fake([
            'api.stripe.com/v1/payment_intents/pi_declined*' => Http::response($this->intent(['id' => 'pi_declined', 'status' => 'requires_payment_method', 'last_payment_error' => ['type' => 'card_error', 'message' => 'Your card has insufficient funds.']])),
            'api.stripe.com/v1/payment_intents/pi_other*' => Http::response($this->intent(['id' => 'pi_other', 'metadata' => ['invoice_id' => '999']])),
        ]);

        $this->postJson("/portal/invoices/{$this->invoice->id}/stripe/confirm", ['payment_intent' => 'pi_declined'])
            ->assertStatus(422)->assertJson(['message' => 'Your card has insufficient funds.']);
        $this->postJson("/portal/invoices/{$this->invoice->id}/stripe/confirm", ['payment_intent' => 'pi_other'])
            ->assertStatus(422)->assertJson(['message' => 'This payment does not match the invoice. Please contact us.']);
        $this->postJson("/portal/invoices/{$this->invoice->id}/stripe/confirm", ['payment_intent' => '../customers'])->assertStatus(422);

        $this->assertSame(InvoiceStatus::Unpaid, $this->invoice->refresh()->status);
        $this->assertSame(0, Transaction::count());
    }

    public function test_the_return_page_records_a_payment_that_needed_a_redirect(): void
    {
        $this->actingAs($this->client, 'client');
        Http::fake(['api.stripe.com/v1/payment_intents/pi_123*' => Http::response($this->intent())]);

        $this->get("/portal/invoices/{$this->invoice->id}/stripe/return?payment_intent=pi_123&redirect_status=succeeded")
            ->assertRedirect(route('portal.invoices.show', $this->invoice))
            ->assertSessionHas('status', 'Thank you, your payment was received.');
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->refresh()->status);
    }

    public function test_billing_contacts_can_pay_and_others_cannot(): void
    {
        $contact = $this->client->contacts()->create(['first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'pat@example.com', 'portal_access' => true, 'permissions' => ['support']]);
        $contact->forceFill(['password' => 'contact-password-1'])->save();
        $this->post('/portal/login', ['email' => 'pat@example.com', 'password' => 'contact-password-1']);
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['id' => self::INTENT, 'client_secret' => 'pi_123_secret_abc'])]);

        $this->postJson("/portal/invoices/{$this->invoice->id}/stripe/intent")->assertForbidden();
        $this->get('/portal/payment-methods')->assertRedirect('/portal');

        $contact->update(['permissions' => ['billing']]);
        $this->postJson("/portal/invoices/{$this->invoice->id}/stripe/intent")->assertOk();
        $this->get('/portal/payment-methods')->assertOk();
    }

    public function test_webhook_signatures_are_checked(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response($this->intent())]);
        $event = ['id' => 'evt_1', 'type' => 'payment_intent.succeeded', 'data' => ['object' => $this->intent()]];

        $this->webhook($event, secret: 'whsec_wrong')->assertStatus(400);
        $this->webhook($event, time: time() - 600)->assertStatus(400);
        $this->call('POST', '/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($event))->assertStatus(400);
        Http::assertNothingSent();
        $this->assertSame(InvoiceStatus::Unpaid, $this->invoice->refresh()->status);

        $this->webhook($event)->assertOk();
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->refresh()->status);
    }

    public function test_a_header_with_several_signatures_passes_when_one_matches(): void
    {
        $body = '{"id":"evt_1"}';
        $time = time();
        $good = hash_hmac('sha256', "{$time}.{$body}", self::SECRET);

        $this->assertTrue(app(Stripe::class)->verifyWebhook($body, "t={$time},v1=deadbeef,v1={$good},v0=old"));
        $this->assertFalse(app(Stripe::class)->verifyWebhook($body.' ', "t={$time},v1={$good}"));
    }

    public function test_payment_intent_succeeded_is_recorded_once(): void
    {
        Http::fake(['api.stripe.com/v1/payment_intents/pi_123*' => Http::response($this->intent())]);
        $event = ['id' => 'evt_1', 'type' => 'payment_intent.succeeded', 'data' => ['object' => $this->intent()]];

        $this->webhook($event)->assertOk();
        $this->webhook($event)->assertOk();

        $this->assertSame(1, Transaction::count());
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->refresh()->status);
    }

    public function test_staff_refund_through_stripe(): void
    {
        $payment = $this->recordedPayment();
        $this->actingAs(User::factory()->create(), 'web');
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded'])]);

        $this->post("/admin/transactions/{$payment->id}/refund", ['amount' => '10.00', 'mode' => 'gateway'])->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $r) => $r['payment_intent'] === self::INTENT && $r['amount'] === 1000 && $r->hasHeader('Idempotency-Key'));
        $refund = Transaction::where('amount', '<', 0)->sole();
        $this->assertSame([-1000, 're_1', false], [$refund->amount, $refund->gateway_reference, $refund->pending]);
    }

    public function test_refunds_from_the_stripe_dashboard_are_recorded_from_the_webhook(): void
    {
        $this->recordedPayment();
        Http::fake(['api.stripe.com/v1/refunds*' => Http::response(['data' => [['id' => 're_2', 'amount' => 2500, 'status' => 'succeeded']]])]);
        $event = ['id' => 'evt_2', 'type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => self::INTENT]]];

        $this->webhook($event)->assertOk();
        $this->webhook($event)->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'payment_intent=pi_123'));
        $refund = Transaction::where('amount', '<', 0)->sole();
        $this->assertSame([-2500, 're_2'], [$refund->amount, $refund->gateway_reference]);
        $this->assertSame(InvoiceStatus::Refunded, $this->invoice->refresh()->status);
    }

    public function test_a_pending_refund_is_settled_by_the_webhook(): void
    {
        $payment = $this->recordedPayment();
        $this->actingAs(User::factory()->create(), 'web');
        Http::fake([
            'api.stripe.com/v1/refunds' => Http::response(['id' => 're_3', 'status' => 'pending']),
            'api.stripe.com/v1/refunds?*' => Http::response(['data' => [['id' => 're_3', 'amount' => 2500, 'status' => 'failed']]]),
        ]);
        $this->post("/admin/transactions/{$payment->id}/refund", ['amount' => '25.00', 'mode' => 'gateway']);
        $this->assertTrue(Transaction::where('gateway_reference', 're_3')->sole()->pending);

        $this->webhook(['id' => 'evt_3', 'type' => 'charge.refund.updated', 'data' => ['object' => ['id' => 're_3', 'object' => 'refund', 'payment_intent' => self::INTENT, 'status' => 'failed']]])->assertOk();

        $this->assertFalse(Transaction::where('gateway_reference', 're_3')->exists());
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->refresh()->status);
    }

    public function test_disputes_are_tracked_and_a_lost_one_is_a_refund(): void
    {
        $payment = $this->recordedPayment();
        $dispute = ['id' => 'dp_1', 'object' => 'dispute', 'payment_intent' => self::INTENT, 'amount' => 2500, 'currency' => 'usd', 'status' => 'needs_response', 'reason' => 'fraudulent'];

        $this->webhook(['id' => 'evt_4', 'type' => 'charge.dispute.created', 'data' => ['object' => $dispute]])->assertOk();
        $this->assertSame('needs_response', $payment->refresh()->dispute_status);

        $this->webhook(['id' => 'evt_5', 'type' => 'charge.dispute.closed', 'data' => ['object' => ['status' => 'lost'] + $dispute]])->assertOk();
        $this->assertSame('lost', $payment->refresh()->dispute_status);
        $this->assertSame(-2500, Transaction::where('gateway_reference', 'dispute-dp_1')->sole()->amount);
    }

    public function test_zero_decimal_currencies_are_sent_in_whole_units(): void
    {
        $this->actingAs($this->client, 'client');
        $this->client->forceFill(['currency' => 'JPY'])->save();
        $invoice = $this->invoiceFor($this->client, 'JPY', 150000); // ¥1,500
        Http::fake([
            'api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_jpy', 'client_secret' => 'pi_jpy_secret_abc']),
            'api.stripe.com/v1/payment_intents/pi_jpy*' => Http::response($this->intent([
                'id' => 'pi_jpy', 'amount' => 1500, 'amount_received' => 1500, 'currency' => 'jpy',
                'metadata' => ['invoice_id' => (string) $invoice->id],
                'latest_charge' => ['balance_transaction' => ['fee' => 54, 'currency' => 'jpy']],
            ])),
        ]);

        $this->get("/portal/invoices/{$invoice->id}")->assertSee('amount: 1500', false);
        $this->postJson("/portal/invoices/{$invoice->id}/stripe/intent")->assertOk();
        Http::assertSent(fn (Request $r) => $r->url() === self::PAYMENT_INTENTS_URL && $r['amount'] === 1500 && $r['currency'] === 'jpy');

        $this->postJson("/portal/invoices/{$invoice->id}/stripe/confirm", ['payment_intent' => 'pi_jpy'])->assertOk();
        $transaction = $invoice->transactions()->sole();
        $this->assertSame([150000, 5400], [$transaction->amount, $transaction->fee]);
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
    }

    public function test_amount_conversion(): void
    {
        $this->assertSame([2500, 1500, 16, 12340], [
            Stripe::toStripe(2500, 'USD'), Stripe::toStripe(150000, 'JPY'), Stripe::toStripe(1501, 'krw'), Stripe::toStripe(1234, 'KWD'),
        ]);
        $this->assertSame([2500, 150000, 1234], [Stripe::fromStripe(2500, 'usd'), Stripe::fromStripe(1500, 'JPY'), Stripe::fromStripe(12340, 'KWD')]);
    }

    public function test_cards_added_or_removed_at_stripe_follow_from_webhooks(): void
    {
        Http::fake(['api.stripe.com/v1/setup_intents/seti_1*' => Http::response([
            'id' => 'seti_1', 'status' => 'succeeded', 'customer' => 'cus_1', 'metadata' => ['client_id' => (string) $this->client->id],
            'payment_method' => ['id' => 'pm_5', 'type' => 'card', 'card' => ['brand' => 'mastercard', 'last4' => '4444', 'exp_month' => 1, 'exp_year' => 2031]],
        ])]);

        $this->webhook(['id' => 'evt_6', 'type' => 'setup_intent.succeeded', 'data' => ['object' => ['id' => 'seti_1', 'metadata' => ['client_id' => (string) $this->client->id]]]])->assertOk();
        $this->assertSame('Mastercard ending 4444', PaymentMethod::sole()->label());

        $this->webhook(['id' => 'evt_7', 'type' => 'payment_method.detached', 'data' => ['object' => ['id' => 'pm_5', 'object' => 'payment_method']]])->assertOk();
        $this->assertSame(0, PaymentMethod::count());
    }
}
