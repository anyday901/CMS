<?php

namespace Tests\Feature\Payments;

use App\Enums\StaffRole;
use App\Models\Client;
use App\Models\GatewayLog;
use App\Models\Invoice;
use App\Models\User;
use App\Payments\GatewayLogger;
use App\Payments\Gateways\SquareWebhook;
use App\Privacy\ClientAnonymiser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class GatewayLogTest extends TestCase
{
    use RefreshDatabase;

    private const SQUARE_URL = 'https://billing.example.test/webhooks/square';

    private const SQUARE_KEY = 'test-signature-key';

    private const STRIPE_SECRET = 'whsec_test123';

    private const VISA = '4242424242424242';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'payments.square.webhook_signature_key' => self::SQUARE_KEY,
            'payments.square.webhook_url' => self::SQUARE_URL,
            'payments.stripe' => [
                'publishable_key' => 'pk_test_abc', 'secret_key' => 'sk_test_abc', 'webhook_secret' => self::STRIPE_SECRET,
                'webhook_tolerance' => 300, 'api_version' => '2024-06-20',
            ],
        ]);
    }

    private function square(array $event, ?string $signature = null): TestResponse
    {
        $body = json_encode($event);
        $signature ??= base64_encode(hash_hmac('sha256', self::SQUARE_URL.$body, self::SQUARE_KEY, true));

        return $this->call('POST', '/webhooks/square', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $signature,
        ], $body);
    }

    private function invoice(Client $client): Invoice
    {
        $invoice = Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => 2500]);

        return $invoice->recalculate();
    }

    public function test_an_accepted_webhook_is_logged_with_its_event(): void
    {
        $this->square(['type' => 'refund.updated', 'event_id' => 'evt-1', 'data' => ['object' => ['refund' => ['id' => 'R1', 'payment_id' => 'NONE']]]])->assertOk();

        $entry = GatewayLog::sole();
        $this->assertSame('cashapp', $entry->gateway);
        $this->assertSame('webhooks/square', $entry->endpoint);
        $this->assertSame('refund.updated', $entry->event);
        $this->assertSame(GatewayLog::ACCEPTED, $entry->outcome);
        $this->assertSame(200, $entry->response_status);
        $this->assertSame('evt-1', json_decode($entry->payload, true)['event_id']);
    }

    public function test_rejected_webhooks_are_logged_without_their_signatures(): void
    {
        $this->square(['type' => 'refund.created', 'event_id' => 'evt-2'], 'bogus')->assertStatus(400);
        $this->call('POST', '/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't=1,v1=abc'], json_encode(['id' => 'evt_3', 'type' => 'payment_intent.succeeded']))->assertStatus(400);
        // PayPal isn't set up in tests, so its webhook turns everything away.
        $this->postJson('/webhooks/paypal', ['id' => 'WH-1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED'])->assertStatus(400);

        $this->assertSame(3, GatewayLog::where('outcome', GatewayLog::REJECTED)->count());
        $this->assertSame(['cashapp', 'paypal', 'stripe'], GatewayLog::orderBy('gateway')->pluck('gateway')->all());
        $this->assertSame('PAYMENT.CAPTURE.COMPLETED', GatewayLog::where('gateway', 'paypal')->value('event'));
        $this->assertStringNotContainsString('bogus', (string) GatewayLog::pluck('payload')->join(''));
    }

    public function test_an_error_while_handling_a_webhook_is_logged_as_an_error(): void
    {
        $this->partialMock(SquareWebhook::class)->shouldReceive('handle')->andThrow(new RuntimeException('Database went away'));

        $this->square(['type' => 'dispute.created', 'event_id' => 'evt-4'])->assertStatus(500);

        $entry = GatewayLog::sole();
        $this->assertSame(GatewayLog::ERROR, $entry->outcome);
        $this->assertSame(500, $entry->response_status);
        $this->assertSame('RuntimeException: Database went away', $entry->message);
    }

    public function test_secrets_and_card_data_are_redacted(): void
    {
        $redacted = app(GatewayLogger::class)->redact([
            'id' => 'pi_1',
            'client_secret' => 'pi_1_secret_abc',
            'payment_method' => ['card' => ['brand' => 'visa', 'last4' => '4242', 'number' => self::VISA, 'exp_month' => 4, 'exp_year' => 2030, 'cvc' => '123']],
            'source_id' => 'cnon:card-nonce-ok',
            'note' => 'Card '.self::VISA.' was used',
            'order' => ['id' => '1234567890123', 'access_token' => 'A21AA'],
        ]);

        $this->assertSame('pi_1', $redacted['id']);
        $this->assertSame(GatewayLogger::REDACTED, $redacted['client_secret']);
        $this->assertSame(['brand' => 'visa', 'last4' => '4242', 'number' => '[redacted]', 'exp_month' => '[redacted]', 'exp_year' => '[redacted]', 'cvc' => '[redacted]'], $redacted['payment_method']['card']);
        $this->assertSame(GatewayLogger::REDACTED, $redacted['source_id']);
        $this->assertSame('Card [redacted] was used', $redacted['note']);
        // An order number that isn't a card number stays.
        $this->assertSame('1234567890123', $redacted['order']['id']);
        $this->assertSame(GatewayLogger::REDACTED, $redacted['order']['access_token']);
    }

    public function test_returns_and_confirmations_from_the_portal_are_logged_with_the_client_and_invoice(): void
    {
        $client = Client::factory()->create();
        $invoice = $this->invoice($client);
        $this->actingAs($client, 'client');
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'No such payment_intent']], 404)]);

        $this->get("/portal/invoices/{$invoice->id}/stripe/return?payment_intent=pi_123&payment_intent_client_secret=pi_123_secret_xyz&redirect_status=succeeded")
            ->assertRedirect(route('portal.invoices.show', $invoice));
        $this->postJson('/portal/invoices/'.$invoice->id.'/cashapp', ['token' => 'cnon:abc'])->assertNotFound();

        $return = GatewayLog::where('gateway', 'stripe')->sole();
        $this->assertSame($client->id, $return->client_id);
        $this->assertSame($invoice->id, $return->invoice_id);
        $this->assertSame(GatewayLog::REJECTED, $return->outcome);
        $this->assertNotNull($return->message);
        $payload = json_decode($return->payload, true);
        $this->assertSame('pi_123', $payload['payment_intent']);
        $this->assertSame(GatewayLogger::REDACTED, $payload['payment_intent_client_secret']);

        // Cash App isn't set up, so the charge is turned away; the token isn't kept.
        $cashApp = GatewayLog::where('gateway', 'cashapp')->sole();
        $this->assertSame(GatewayLog::REJECTED, $cashApp->outcome);
        $this->assertSame(GatewayLogger::REDACTED, json_decode($cashApp->payload, true)['token']);
    }

    public function test_staff_who_manage_settings_list_filter_and_read_the_log(): void
    {
        $client = Client::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $invoice = $this->invoice($client);
        GatewayLog::create(['gateway' => 'stripe', 'endpoint' => 'webhooks/stripe', 'event' => 'charge.refunded', 'outcome' => GatewayLog::ACCEPTED, 'response_status' => 200, 'payload' => '{"id":"evt_ok"}', 'client_id' => $client->id, 'invoice_id' => $invoice->id]);
        GatewayLog::create(['gateway' => 'paypal', 'endpoint' => 'webhooks/paypal', 'event' => 'CUSTOMER.DISPUTE.CREATED', 'outcome' => GatewayLog::REJECTED, 'response_status' => 400, 'payload' => '{"id":"WH-bad"}']);
        $this->actingAs(User::factory()->create(), 'web');

        $this->get('/admin/settings/payment-gateways')->assertSee('Gateway log');
        $this->get('/admin/settings/gateway-log')->assertOk()->assertSee('charge.refunded')->assertSee('CUSTOMER.DISPUTE.CREATED');
        $this->get('/admin/settings/gateway-log?gateway=stripe')->assertOk()->assertSee('charge.refunded')->assertDontSee('CUSTOMER.DISPUTE.CREATED');
        $this->get('/admin/settings/gateway-log?outcome=rejected')->assertOk()->assertDontSee('charge.refunded')->assertSee('CUSTOMER.DISPUTE.CREATED');
        $this->get('/admin/settings/gateway-log?q=WH-bad')->assertOk()->assertDontSee('charge.refunded')->assertSee('CUSTOMER.DISPUTE.CREATED');

        $entry = GatewayLog::where('gateway', 'stripe')->sole();
        $this->get("/admin/settings/gateway-log/{$entry->id}")->assertOk()
            ->assertSee('charge.refunded')->assertSee('Ada Lovelace')->assertSee("#{$invoice->number}")->assertSee('evt_ok');

        $this->actingAs(User::factory()->create(['role' => StaffRole::Support]), 'web');
        $this->get('/admin/settings/gateway-log')->assertForbidden();
        $this->get("/admin/settings/gateway-log/{$entry->id}")->assertForbidden();
    }

    public function test_anonymising_a_client_deletes_what_gateways_sent_about_them(): void
    {
        $client = Client::factory()->create();
        $invoice = $this->invoice($client);
        GatewayLog::create(['gateway' => 'stripe', 'endpoint' => 'webhooks/stripe', 'outcome' => GatewayLog::ACCEPTED, 'response_status' => 200, 'client_id' => $client->id]);
        GatewayLog::create(['gateway' => 'paypal', 'endpoint' => 'webhooks/paypal', 'outcome' => GatewayLog::ACCEPTED, 'response_status' => 200, 'invoice_id' => $invoice->id]);
        $other = GatewayLog::create(['gateway' => 'paypal', 'endpoint' => 'webhooks/paypal', 'outcome' => GatewayLog::ACCEPTED, 'response_status' => 200]);

        app(ClientAnonymiser::class)->anonymise($client);

        $this->assertSame([$other->id], GatewayLog::pluck('id')->all());
    }

    public function test_entries_are_pruned_with_the_activity_log_retention(): void
    {
        $old = GatewayLog::create(['gateway' => 'stripe', 'endpoint' => 'webhooks/stripe', 'outcome' => GatewayLog::ACCEPTED, 'response_status' => 200]);
        $old->forceFill(['created_at' => now()->subDays(100)])->save();
        GatewayLog::create(['gateway' => 'stripe', 'endpoint' => 'webhooks/stripe', 'outcome' => GatewayLog::ACCEPTED, 'response_status' => 200]);

        $this->artisan('model:prune', ['--model' => [GatewayLog::class]])->assertSuccessful();
        $this->assertSame(2, GatewayLog::count());

        config(['activity.retention_days' => 90]);
        $this->artisan('model:prune', ['--model' => [GatewayLog::class]])->assertSuccessful();
        $this->assertModelMissing($old);
        $this->assertSame(1, GatewayLog::count());
    }
}
