<?php

namespace Tests\Feature\Payments;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayPalTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.paypal' => [
            'mode' => 'sandbox', 'client_id' => 'cid', 'secret' => 'sec', 'webhook_id' => 'WH-1', 'venmo' => true,
        ]]);

        $this->client = Client::factory()->create();
        $this->invoice = Invoice::create(['client_id' => $this->client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $this->invoice->items()->create(['description' => 'Hosting', 'amount' => 1500]);
        $this->invoice->recalculate();
    }

    private function capture(array $overrides = [], string $source = 'paypal'): array
    {
        return [
            'id' => 'ORDER-1',
            'status' => 'COMPLETED',
            'payment_source' => [$source => []],
            'purchase_units' => [['payments' => ['captures' => [array_merge([
                'id' => 'CAP-1',
                'status' => 'COMPLETED',
                'custom_id' => (string) $this->invoice->id,
                'amount' => ['currency_code' => 'USD', 'value' => '15.00'],
                'seller_receivable_breakdown' => ['paypal_fee' => ['currency_code' => 'USD', 'value' => '0.82']],
            ], $overrides)]]]],
        ];
    }

    private function fakePayPal(array $capture): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response(['id' => 'ORDER-1'], 201),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-1/capture' => Http::response($capture, 201),
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
        ]);
    }

    public function test_invoice_page_shows_paypal_button_when_configured(): void
    {
        $this->actingAs($this->client, 'client');

        $this->get("/portal/invoices/{$this->invoice->id}")->assertSee('paypal-buttons')->assertSee('enable-funding=venmo');

        config(['payments.paypal.client_id' => null]);
        $this->get("/portal/invoices/{$this->invoice->id}")->assertDontSee('paypal-buttons');
    }

    public function test_order_and_capture_pays_the_invoice(): void
    {
        $this->fakePayPal($this->capture([], 'venmo'));
        $this->actingAs($this->client, 'client');

        $this->postJson("/portal/invoices/{$this->invoice->id}/paypal/order")->assertOk()->assertJson(['id' => 'ORDER-1']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v2/checkout/orders')
            && $r['purchase_units'][0]['amount']['value'] === '15.00'
            && $r['purchase_units'][0]['custom_id'] === (string) $this->invoice->id);

        $this->postJson("/portal/invoices/{$this->invoice->id}/paypal/capture", ['order_id' => 'ORDER-1'])
            ->assertOk()->assertJson(['redirect' => route('portal.invoices.show', $this->invoice)]);

        $this->invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->status);
        $transaction = $this->invoice->transactions->sole();
        $this->assertSame('paypal', $transaction->gateway);
        $this->assertSame('venmo', $transaction->payment_method);
        $this->assertSame('CAP-1', $transaction->gateway_reference);
        $this->assertSame(82, $transaction->fee);
        $this->assertSame('Venmo', $transaction->methodLabel());
    }

    public function test_capture_for_a_different_invoice_is_rejected(): void
    {
        $this->fakePayPal($this->capture(['custom_id' => '999']));
        $this->actingAs($this->client, 'client');

        $this->postJson("/portal/invoices/{$this->invoice->id}/paypal/capture", ['order_id' => 'ORDER-1'])->assertStatus(422);
        $this->assertSame(InvoiceStatus::Unpaid, $this->invoice->refresh()->status);
    }

    public function test_pending_capture_is_not_recorded_until_webhook(): void
    {
        $this->fakePayPal($this->capture(['status' => 'PENDING']));
        $this->actingAs($this->client, 'client');

        $this->postJson("/portal/invoices/{$this->invoice->id}/paypal/capture", ['order_id' => 'ORDER-1'])->assertOk();
        $this->assertSame(InvoiceStatus::Unpaid, $this->invoice->refresh()->status);

        $resource = $this->capture()['purchase_units'][0]['payments']['captures'][0];
        $this->postJson('/webhooks/paypal', ['id' => 'WH-EVT', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => $resource])
            ->assertOk();
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->refresh()->status);

        // A repeated webhook does not double count.
        $this->postJson('/webhooks/paypal', ['id' => 'WH-EVT', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => $resource])
            ->assertOk();
        $this->assertSame(1, $this->invoice->transactions()->count());
    }

    public function test_unverified_webhook_is_rejected(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE']),
        ]);
        $resource = $this->capture()['purchase_units'][0]['payments']['captures'][0];

        $this->postJson('/webhooks/paypal', ['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => $resource])->assertStatus(400);
        $this->assertSame(InvoiceStatus::Unpaid, $this->invoice->refresh()->status);
    }

    public function test_cannot_pay_someone_elses_or_a_paid_invoice(): void
    {
        $this->fakePayPal($this->capture());
        $this->actingAs(Client::factory()->create(), 'client');
        $this->postJson("/portal/invoices/{$this->invoice->id}/paypal/order")->assertNotFound();

        $this->actingAs($this->client, 'client');
        $this->postJson("/portal/invoices/{$this->invoice->id}/paypal/capture", ['order_id' => 'ORDER-1'])->assertOk();
        $this->postJson("/portal/invoices/{$this->invoice->id}/paypal/order")->assertStatus(409);
    }
}
