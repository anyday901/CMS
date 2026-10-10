<?php

namespace Tests\Feature\Payments;

use App\Billing\PaymentRecorder;
use App\Enums\InvoiceStatus;
use App\Enums\StaffRole;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\StaffAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PayPalDisputeTest extends TestCase
{
    use RefreshDatabase;

    private Invoice $invoice;

    private Transaction $payment;

    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.paypal' => ['mode' => 'sandbox', 'client_id' => 'cid', 'secret' => 'sec', 'webhook_id' => 'WH-1', 'venmo' => true]]);
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
        ]);
        Notification::fake();

        $client = Client::factory()->create();
        $this->invoice = Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $this->invoice->items()->create(['description' => 'Hosting', 'amount' => 1500]);
        $this->invoice->recalculate();
        $this->payment = app(PaymentRecorder::class)->record($this->invoice, 1500, 'paypal', 'CAP-1');
    }

    private function dispute(string $status, ?string $outcome = null, string $capture = 'CAP-1', string $type = 'CUSTOMER.DISPUTE.UPDATED'): TestResponse
    {
        return $this->postJson('/webhooks/paypal', ['id' => 'WH-EVT-'.$status, 'event_type' => $type, 'resource' => array_filter([
            'dispute_id' => 'PP-D-1',
            'reason' => 'MERCHANDISE_OR_SERVICE_NOT_RECEIVED',
            'status' => $status,
            'dispute_amount' => ['currency_code' => 'USD', 'value' => '15.00'],
            'disputed_transactions' => [['seller_transaction_id' => $capture]],
            'dispute_outcome' => $outcome ? ['outcome_code' => $outcome] : null,
        ])]);
    }

    public function test_a_new_dispute_is_shown_on_the_payment_and_billing_staff_are_emailed(): void
    {
        $billing = User::factory()->create(['role' => StaffRole::Billing]);
        $support = User::factory()->create(['role' => StaffRole::Support]);

        $this->dispute('WAITING_FOR_SELLER_RESPONSE', type: 'CUSTOMER.DISPUTE.CREATED')->assertOk();

        $this->assertSame('waiting_for_seller_response', $this->payment->fresh()->dispute_status);
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->fresh()->status);
        Notification::assertSentTo($billing, StaffAlert::class, fn (StaffAlert $alert) => str_contains($alert->subject, 'waiting for seller response'));
        Notification::assertNotSentTo($support, StaffAlert::class);
    }

    public function test_a_lost_dispute_is_recorded_as_a_refund_once(): void
    {
        $this->dispute('RESOLVED', 'RESOLVED_BUYER_FAVOUR', type: 'CUSTOMER.DISPUTE.RESOLVED')->assertOk();
        $this->dispute('RESOLVED', 'RESOLVED_BUYER_FAVOUR', type: 'CUSTOMER.DISPUTE.RESOLVED')->assertOk(); // PayPal retries

        $this->assertSame('lost', $this->payment->fresh()->dispute_status);
        $this->assertSame(-1500, Transaction::where('gateway_reference', 'dispute-PP-D-1')->sole()->amount);
        $this->assertSame(InvoiceStatus::Refunded, $this->invoice->fresh()->status);
    }

    public function test_a_won_dispute_leaves_the_payment_alone(): void
    {
        $this->dispute('UNDER_REVIEW')->assertOk();
        $this->dispute('RESOLVED', 'RESOLVED_SELLER_FAVOUR', type: 'CUSTOMER.DISPUTE.RESOLVED')->assertOk();

        $this->assertSame('won', $this->payment->fresh()->dispute_status);
        $this->assertSame(1, Transaction::count());
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->fresh()->status);
    }

    public function test_disputes_on_payments_this_app_did_not_take_are_ignored(): void
    {
        $this->dispute('OPEN', capture: 'SOMEONE-ELSE')->assertOk();

        $this->assertNull($this->payment->fresh()->dispute_status);
        Notification::assertNothingSent();
    }

    public function test_dispute_webhooks_that_cannot_be_verified_are_rejected(): void
    {
        config(['payments.paypal.webhook_id' => null]); // Nothing to verify against.

        $this->dispute('RESOLVED', 'RESOLVED_BUYER_FAVOUR')->assertStatus(400);

        $this->assertNull($this->payment->fresh()->dispute_status);
    }
}
