<?php

namespace Tests\Feature\Payments;

use App\Billing\PaymentRecorder;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SquareWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://billing.example.test/webhooks/square';

    private const KEY = 'test-signature-key';

    private const PAYMENT_ID = 'SQ-PAY-1';

    private Invoice $invoice;

    private Transaction $payment;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'payments.square.webhook_signature_key' => self::KEY,
            'payments.square.webhook_url' => self::URL,
        ]);

        $client = Client::factory()->create();
        $this->invoice = Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $this->invoice->items()->create(['description' => 'Hosting', 'amount' => 2500]);
        $this->invoice->recalculate();
        $this->payment = app(PaymentRecorder::class)->record($this->invoice, 2500, 'cashapp', self::PAYMENT_ID);
    }

    private function send(array $event, ?string $signature = null): TestResponse
    {
        $body = json_encode($event);
        $signature ??= base64_encode(hash_hmac('sha256', self::URL.$body, self::KEY, true));

        return $this->call('POST', '/webhooks/square', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $signature,
        ], $body);
    }

    private function refundEvent(string $id, string $status, int $amount, string $type = 'refund.created'): array
    {
        return ['type' => $type, 'event_id' => 'evt-'.$id.$status, 'data' => ['object' => ['refund' => [
            'id' => $id, 'status' => $status, 'payment_id' => self::PAYMENT_ID,
            'amount_money' => ['amount' => $amount, 'currency' => 'USD'],
        ]]]];
    }

    public function test_unsigned_or_badly_signed_events_are_rejected(): void
    {
        $this->send($this->refundEvent('R1', 'COMPLETED', 500), 'bogus')->assertStatus(400);

        config(['payments.square.webhook_signature_key' => null]);
        $this->send($this->refundEvent('R1', 'COMPLETED', 500))->assertStatus(400);

        $this->assertSame(1, Transaction::count());
    }

    public function test_a_refund_made_in_the_square_dashboard_is_recorded_once(): void
    {
        $this->send($this->refundEvent('R1', 'PENDING', 1000))->assertOk();
        $this->send($this->refundEvent('R1', 'PENDING', 1000))->assertOk(); // Square retries

        $refund = Transaction::where('gateway_reference', 'R1')->sole();
        $this->assertSame(-1000, $refund->amount);
        $this->assertTrue($refund->pending);

        $this->send($this->refundEvent('R1', 'COMPLETED', 1000, 'refund.updated'))->assertOk();
        $this->assertFalse($refund->fresh()->pending);
        $this->assertSame(1500, $this->payment->refundable());
    }

    public function test_a_failed_refund_puts_the_payment_back(): void
    {
        $this->send($this->refundEvent('R2', 'PENDING', 2500))->assertOk();
        $this->assertSame(InvoiceStatus::Refunded, $this->invoice->fresh()->status);

        $this->send($this->refundEvent('R2', 'FAILED', 2500, 'refund.updated'))->assertOk();

        $this->assertNull(Transaction::where('gateway_reference', 'R2')->first());
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->fresh()->status);
    }

    public function test_refunds_for_payments_this_app_did_not_take_are_ignored(): void
    {
        $event = $this->refundEvent('R3', 'COMPLETED', 500);
        $event['data']['object']['refund']['payment_id'] = 'SOMEONE-ELSE';

        $this->send($event)->assertOk();
        $this->assertSame(1, Transaction::count());
    }

    public function test_disputes_are_tracked_and_a_lost_dispute_counts_as_a_refund(): void
    {
        $dispute = fn (string $state) => ['type' => 'dispute.state.updated', 'data' => ['object' => ['dispute' => [
            'id' => 'D1', 'state' => $state, 'reason' => 'NOT_AS_DESCRIBED',
            'amount_money' => ['amount' => 2500, 'currency' => 'USD'],
            'disputed_payment' => ['payment_id' => self::PAYMENT_ID],
        ]]]];

        $this->send($dispute('EVIDENCE_REQUIRED'))->assertOk();
        $this->assertSame('evidence_required', $this->payment->fresh()->dispute_status);
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->fresh()->status);

        $this->send($dispute('LOST'))->assertOk();
        $this->send($dispute('LOST'))->assertOk();

        $this->assertSame('lost', $this->payment->fresh()->dispute_status);
        $this->assertSame(-2500, (int) Transaction::where('gateway_reference', 'dispute-D1')->sole()->amount);
        $this->assertSame(InvoiceStatus::Refunded, $this->invoice->fresh()->status);
    }
}
