<?php

namespace Tests\Feature\Payments;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\User;
use App\Payments\Gateways\Stripe;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SavedPaymentMethodsTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = '/portal/payment-methods';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.stripe' => [
            'publishable_key' => 'pk_test_abc', 'secret_key' => 'sk_test_abc', 'webhook_secret' => 'whsec_test',
            'webhook_tolerance' => 300, 'api_version' => '2024-06-20',
        ]]);
        $this->client = Client::factory()->create();
    }

    private function card(string $reference, bool $default = false, ?Client $client = null, string $last4 = '4242'): PaymentMethod
    {
        return PaymentMethod::create([
            'client_id' => ($client ?? $this->client)->id, 'gateway' => 'stripe', 'gateway_customer_id' => 'cus_1', 'gateway_reference' => $reference,
            'brand' => 'visa', 'last4' => $last4, 'exp_month' => 4, 'exp_year' => 2030, 'is_default' => $default,
        ]);
    }

    private function invoice(): Invoice
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => 2500]);
        $invoice->recalculate();

        return $invoice;
    }

    public function test_a_client_adds_a_card_with_a_setup_intent(): void
    {
        $this->actingAs($this->client, 'client');
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
            'api.stripe.com/v1/setup_intents' => Http::response(['id' => 'seti_1', 'client_secret' => 'seti_1_secret_abc']),
            'api.stripe.com/v1/setup_intents/seti_1*' => Http::response([
                'id' => 'seti_1', 'status' => 'succeeded', 'customer' => 'cus_1', 'metadata' => ['client_id' => (string) $this->client->id],
                'payment_method' => ['id' => 'pm_1', 'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 4, 'exp_year' => 2030]],
            ]),
        ]);

        $this->get(self::PAGE)->assertOk()->assertSee('You have no saved cards.')->assertSee('Add a card')->assertSee('mode: \'setup\'', false);
        $this->get('/portal/invoices')->assertSee('Payment methods');

        $this->postJson(self::PAGE.'/stripe/setup')->assertOk()->assertJson(['client_secret' => 'seti_1_secret_abc']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/setup_intents' && $r['customer'] === 'cus_1' && $r['usage'] === 'off_session');

        $this->postJson(self::PAGE.'/stripe/confirm', ['setup_intent' => 'seti_1'])->assertOk()->assertJson(['redirect' => route('portal.payment-methods.index')]);

        $card = PaymentMethod::sole();
        $this->assertSame([$this->client->id, 'Visa ending 4242', true], [$card->client_id, $card->label(), $card->is_default]);
        $this->get(self::PAGE)->assertSee('Visa ending 4242')->assertSee('Automatic payments');
    }

    public function test_a_setup_intent_of_another_client_is_refused(): void
    {
        $this->actingAs($this->client, 'client');
        Http::fake(['api.stripe.com/v1/setup_intents/seti_1*' => Http::response([
            'id' => 'seti_1', 'status' => 'succeeded', 'customer' => 'cus_9', 'metadata' => ['client_id' => '999'],
            'payment_method' => ['id' => 'pm_9', 'type' => 'card', 'card' => []],
        ])]);

        $this->postJson(self::PAGE.'/stripe/confirm', ['setup_intent' => 'seti_1'])->assertStatus(422);
        $this->assertSame(0, PaymentMethod::count());
    }

    public function test_default_and_remove(): void
    {
        $this->actingAs($this->client, 'client');
        $first = $this->card('pm_1', true);
        $second = $this->card('pm_2', false, last4: '1111');
        $theirs = $this->card('pm_9', true, Client::factory()->create());
        Http::fake(['api.stripe.com/v1/payment_methods/*/detach' => Http::response(['id' => 'pm_1'])]);

        $this->post(self::PAGE."/{$second->id}/default")->assertRedirect();
        $this->assertSame([false, true], [$first->refresh()->is_default, $second->refresh()->is_default]);

        $this->delete(self::PAGE."/{$second->id}")->assertRedirect();
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/payment_methods/pm_2/detach');
        $this->assertModelMissing($second);
        // The other card takes over as the default.
        $this->assertTrue($first->refresh()->is_default);

        $this->post(self::PAGE."/{$theirs->id}/default")->assertNotFound();
        $this->delete(self::PAGE."/{$theirs->id}")->assertNotFound();
        $this->assertModelExists($theirs);
    }

    public function test_a_card_stripe_will_not_detach_is_kept(): void
    {
        $this->actingAs($this->client, 'client');
        $card = $this->card('pm_1', true);
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'Server error']], 500)]);

        $this->delete(self::PAGE."/{$card->id}")->assertSessionHasErrors('payment_method');
        $this->assertModelExists($card);
    }

    public function test_clients_turn_automatic_charging_off_and_on(): void
    {
        $this->actingAs($this->client, 'client');
        $this->card('pm_1', true);
        $this->assertTrue($this->client->refresh()->auto_charge);

        $this->get("/portal/invoices/{$this->invoice()->id}")->assertSee('Visa ending 4242 will be charged automatically');

        $this->put(self::PAGE.'/auto-charge', ['auto_charge' => '0'])->assertRedirect();
        $this->assertFalse($this->client->refresh()->auto_charge);
        $this->get(self::PAGE)->assertSee('Turn automatic payments on');

        $this->put(self::PAGE.'/auto-charge', ['auto_charge' => '1']);
        $this->assertTrue($this->client->refresh()->auto_charge);
    }

    public function test_contacts_without_billing_cannot_manage_cards(): void
    {
        $card = $this->card('pm_1', true);
        $contact = $this->client->contacts()->create(['first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'pat@example.com', 'portal_access' => true, 'permissions' => ['support']]);
        $contact->forceFill(['password' => 'contact-password-1'])->save();
        $this->post('/portal/login', ['email' => 'pat@example.com', 'password' => 'contact-password-1']);

        $this->get(self::PAGE)->assertRedirect('/portal');
        $this->delete(self::PAGE."/{$card->id}")->assertRedirect('/portal');
        $this->put(self::PAGE.'/auto-charge', ['auto_charge' => '0'])->assertRedirect('/portal');
        $this->postJson(self::PAGE.'/stripe/setup')->assertForbidden();
        $this->assertModelExists($card);
        $this->assertTrue($this->client->refresh()->auto_charge);
    }

    public function test_staff_see_remove_and_charge_saved_cards(): void
    {
        $card = $this->card('pm_1', true);
        $invoice = $this->invoice();
        $this->actingAs(User::factory()->create(), 'web');
        Http::fake([
            'api.stripe.com/v1/payment_intents' => Http::sequence()
                ->push(['error' => ['type' => 'card_error', 'code' => 'card_declined', 'message' => 'Your card was declined.']], 402)
                ->push(['id' => 'pi_1', 'status' => 'succeeded', 'amount_received' => 2500, 'currency' => 'usd', 'metadata' => ['invoice_id' => (string) $invoice->id]]),
            'api.stripe.com/v1/payment_methods/*' => Http::response(['id' => 'pm_1']),
        ]);

        $this->get("/admin/clients/{$this->client->id}?tab=payment-methods")->assertOk()->assertSee('Visa ending 4242')->assertSee('04/2030');
        $this->get("/admin/invoices/{$invoice->id}")->assertSee('Charge saved card');

        $this->post("/admin/invoices/{$invoice->id}/charge", ['payment_method' => $card->id])->assertSessionHasErrors(['charge' => 'The charge failed: Your card was declined.']);
        $this->assertSame([PaymentAttempt::FAILED, PaymentAttempt::STAFF], [PaymentAttempt::sole()->status, PaymentAttempt::sole()->source]);
        // Staff charges don't email the client about a failure.
        $this->assertFalse(EmailLog::where('template', 'payment_failed')->exists());
        $this->get("/admin/invoices/{$invoice->id}")->assertSee('Saved card charges')->assertSee('Your card was declined.');

        $this->post("/admin/invoices/{$invoice->id}/charge", ['payment_method' => $card->id])->assertSessionHas('status', 'Charged $25.00 to Visa ending 4242.');
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $keys = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0] ?? null)->filter()->unique();
        $this->assertCount(2, $keys);

        $this->put("/admin/clients/{$this->client->id}/auto-charge", ['auto_charge' => '0'])->assertRedirect();
        $this->assertFalse($this->client->refresh()->auto_charge);

        $this->delete("/admin/payment-methods/{$card->id}")->assertRedirect();
        $this->assertModelMissing($card);
    }

    public function test_staff_save_stripe_keys_with_secrets_encrypted(): void
    {
        config(['payments.stripe' => ['publishable_key' => null, 'secret_key' => null, 'webhook_secret' => null]]);
        $this->actingAs(User::factory()->create(), 'web');

        $this->get('/admin/settings')->assertSee('Payment gateways');
        $this->get('/admin/settings/payment-gateways')->assertOk()->assertSee(route('webhooks.stripe'));
        $this->put('/admin/settings/payment-gateways', ['publishable_key' => 'sk_live_oops'])->assertSessionHasErrors('publishable_key');

        $this->put('/admin/settings/payment-gateways', ['publishable_key' => 'pk_test_abc', 'secret_key' => 'sk_test_secret', 'webhook_secret' => 'whsec_abc'])
            ->assertRedirect('/admin/settings/payment-gateways');
        $saved = Setting::where('key', 'payments.stripe.secret_key')->value('value');
        $this->assertNotSame('sk_test_secret', $saved);
        $this->assertSame('sk_test_secret', decrypt($saved, false));

        // A blank secret keeps the saved one.
        $this->put('/admin/settings/payment-gateways', ['publishable_key' => 'pk_test_abc', 'secret_key' => '']);
        Settings::apply();
        $this->assertSame(['pk_test_abc', 'sk_test_secret', 'whsec_abc'], [config('payments.stripe.publishable_key'), config('payments.stripe.secret_key'), config('payments.stripe.webhook_secret')]);
        $this->assertTrue(app(Stripe::class)->isEnabled());
    }
}
