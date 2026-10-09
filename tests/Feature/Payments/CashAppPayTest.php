<?php

namespace Tests\Feature\Payments;

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

    public function test_invoice_page_shows_cash_app_button(): void
    {
        $this->get("/portal/invoices/{$this->invoice->id}")
            ->assertSee('cash-app-pay')
            ->assertSee('sandbox.web.squarecdn.com', false);
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
            && $r['location_id'] === 'LOC1'
            && $r->hasHeader('Authorization', 'Bearer sq-token'));

        $this->invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->status);
        $transaction = $this->invoice->transactions->sole();
        $this->assertSame('cashapp', $transaction->gateway);
        $this->assertSame('SQ-PAY-1', $transaction->gateway_reference);
        $this->assertSame(70, $transaction->fee);
    }

    public function test_declined_charge_leaves_invoice_unpaid(): void
    {
        Http::fake(['connect.squareupsandbox.com/v2/payments' => Http::response(['errors' => [['code' => 'GENERIC_DECLINE']]], 402)]);

        $this->postJson("/portal/invoices/{$this->invoice->id}/cashapp", ['token' => 'cnon:abc'])
            ->assertStatus(422)->assertJsonStructure(['message']);

        $this->assertSame(InvoiceStatus::Unpaid, $this->invoice->refresh()->status);
    }

    public function test_same_token_uses_the_same_idempotency_key(): void
    {
        Http::fake(['connect.squareupsandbox.com/v2/payments' => Http::response(['errors' => []], 500)]);

        $this->postJson("/portal/invoices/{$this->invoice->id}/cashapp", ['token' => 'cnon:abc']);
        $this->postJson("/portal/invoices/{$this->invoice->id}/cashapp", ['token' => 'cnon:abc']);

        $keys = collect(Http::recorded())->map(fn ($pair) => $pair[0]['idempotency_key'])->unique();
        $this->assertCount(1, $keys);
    }
}
