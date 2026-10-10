<?php

namespace Tests\Feature\Billing;

use App\Billing\PaymentRecorder;
use App\Billing\RefundReconciler;
use App\Billing\RefundService;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Client::factory()->create();
        $this->invoice = Invoice::create(['client_id' => $this->client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $this->invoice->items()->create(['description' => 'Hosting', 'amount' => 2000]);
        $this->invoice->recalculate();
    }

    private function pay(string $gateway = 'cash', ?string $reference = null): Transaction
    {
        return app(PaymentRecorder::class)->record($this->invoice, 2000, $gateway, $reference);
    }

    public function test_partial_then_full_manual_refund(): void
    {
        $payment = $this->pay();
        $refunds = app(RefundService::class);

        $refunds->refund($payment, 500, 'manual');
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->refresh()->status);
        $this->assertSame(1500, $payment->refundable());

        $refunds->refund($payment, 1500, 'manual');
        $this->assertSame(InvoiceStatus::Refunded, $this->invoice->refresh()->status);
        $this->assertSame(0, $this->invoice->amountPaid());

        $this->expectException(InvalidArgumentException::class);
        $refunds->refund($payment, 1, 'manual');
    }

    public function test_refund_to_credit_raises_credit_balance(): void
    {
        $refund = app(RefundService::class)->refund($this->pay(), 2000, 'credit');

        $this->assertSame('credit', $refund->gateway);
        $this->assertSame(-2000, $refund->amount);
        $this->assertSame(2000, $this->client->refresh()->credit_balance);
        $this->assertSame(InvoiceStatus::Refunded, $this->invoice->refresh()->status);
    }

    public function test_paypal_refund_goes_through_the_api(): void
    {
        config(['payments.paypal' => ['mode' => 'sandbox', 'client_id' => 'id', 'secret' => 'secret', 'webhook_id' => null, 'venmo' => true]]);
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
            'api-m.sandbox.paypal.com/v2/payments/captures/CAP1/refund' => Http::response(['id' => 'REF1', 'status' => 'COMPLETED'], 201),
        ]);

        $refund = app(RefundService::class)->refund($this->pay('paypal', 'CAP1'), 750, 'gateway');

        $this->assertSame('REF1', $refund->gateway_reference);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/captures/CAP1/refund')
            && $r['amount']['value'] === '7.50'
            && $r->hasHeader('PayPal-Request-Id'));
    }

    public function test_square_refund_goes_through_the_api(): void
    {
        config(['payments.square' => ['environment' => 'sandbox', 'application_id' => 'app', 'access_token' => 'tok', 'location_id' => 'LOC', 'version' => '2024-12-18']]);
        Http::fake(['connect.squareupsandbox.com/v2/refunds' => Http::response(['refund' => ['id' => 'SQREF', 'status' => 'PENDING']])]);

        $refund = app(RefundService::class)->refund($this->pay('cashapp', 'SQPAY'), 2000, 'gateway');

        $this->assertSame('SQREF', $refund->gateway_reference);
        $this->assertTrue($refund->pending);
        Http::assertSent(fn ($r) => $r['payment_id'] === 'SQPAY' && $r['amount_money']['amount'] === 2000);
    }

    public function test_pending_refund_is_confirmed_by_the_nightly_check(): void
    {
        config(['payments.square' => ['environment' => 'sandbox', 'application_id' => 'app', 'access_token' => 'tok', 'location_id' => 'LOC', 'version' => '2024-12-18']]);
        Http::fake([
            'connect.squareupsandbox.com/v2/refunds' => Http::response(['refund' => ['id' => 'SQREF', 'status' => 'PENDING']]),
            'connect.squareupsandbox.com/v2/refunds/SQREF' => Http::response(['refund' => ['id' => 'SQREF', 'status' => 'COMPLETED']]),
        ]);
        $refund = app(RefundService::class)->refund($this->pay('cashapp', 'SQPAY'), 2000, 'gateway');

        $this->assertSame(['completed' => 1, 'failed' => 0], app(RefundReconciler::class)->run());
        $this->assertFalse($refund->refresh()->pending);
        $this->assertSame(InvoiceStatus::Refunded, $this->invoice->refresh()->status);
    }

    public function test_pending_refund_that_fails_is_undone(): void
    {
        config(['payments.paypal' => ['mode' => 'sandbox', 'client_id' => 'id', 'secret' => 'secret', 'webhook_id' => null, 'venmo' => true]]);
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
            'api-m.sandbox.paypal.com/v2/payments/captures/CAP1/refund' => Http::response(['id' => 'REF1', 'status' => 'PENDING'], 201),
            'api-m.sandbox.paypal.com/v2/payments/refunds/REF1' => Http::response(['id' => 'REF1', 'status' => 'FAILED']),
        ]);
        $payment = $this->pay('paypal', 'CAP1');
        app(RefundService::class)->refund($payment, 2000, 'gateway');
        $this->assertSame(InvoiceStatus::Refunded, $this->invoice->refresh()->status);

        $this->assertSame(['completed' => 0, 'failed' => 1], app(RefundReconciler::class)->run());

        $this->assertSame(1, Transaction::count());
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->refresh()->status);
        $this->assertSame(2000, $payment->refundable());
    }

    public function test_gateway_refusal_records_nothing(): void
    {
        config(['payments.paypal' => ['mode' => 'sandbox', 'client_id' => 'id', 'secret' => 'secret', 'webhook_id' => null, 'venmo' => true]]);
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
            'api-m.sandbox.paypal.com/v2/payments/captures/*' => Http::response(['message' => 'Capture already refunded'], 422),
        ]);
        $payment = $this->pay('paypal', 'CAP1');

        try {
            app(RefundService::class)->refund($payment, 2000, 'gateway');
            $this->fail('Expected the refund to fail.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Capture already refunded', $e->getMessage());
        }

        $this->assertSame(1, Transaction::count());
    }

    public function test_cash_payments_cannot_be_refunded_through_a_gateway(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(RefundService::class)->refund($this->pay(), 100, 'gateway');
    }

    public function test_admin_refund_form(): void
    {
        $payment = $this->pay();
        $this->actingAs(User::factory()->create(), 'web');

        $this->get("/admin/invoices/{$this->invoice->id}")->assertSee('Already refunded outside the app')->assertDontSee('Send back through');
        $this->post("/admin/transactions/{$payment->id}/refund", ['amount' => '25.00', 'mode' => 'manual'])
            ->assertSessionHasErrors("refund.{$payment->id}");
        $this->post("/admin/transactions/{$payment->id}/refund", ['amount' => '20.00', 'mode' => 'manual'])
            ->assertSessionHas('status', 'Refund recorded.');

        $this->get("/admin/invoices/{$this->invoice->id}")->assertSee('Refund to Cash');
        $this->assertSame(InvoiceStatus::Refunded, $this->invoice->refresh()->status);
    }
}
