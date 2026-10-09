<?php

namespace Tests\Feature\Billing;

use App\Billing\InvoiceGenerator;
use App\Billing\PaymentRecorder;
use App\Billing\ServiceLifecycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Events\InvoicePaid;
use App\Events\ServiceActivated;
use App\Events\ServiceUnsuspended;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;

class PaymentRecorderTest extends TestCase
{
    use RefreshDatabase;

    private function renewalInvoice(Service $service): Invoice
    {
        return app(InvoiceGenerator::class)
            ->generate(CarbonImmutable::parse($service->next_due_date))
            ->first();
    }

    private function pay(Invoice $invoice, int $amount, ?string $ref = null)
    {
        return app(PaymentRecorder::class)->record($invoice, $amount, 'paypal', $ref);
    }

    public function test_full_payment_marks_paid_and_advances_due_date(): void
    {
        Event::fake([InvoicePaid::class]);
        $service = Service::factory()->create(['next_due_date' => '2026-11-01', 'recurring_amount' => 1000]);
        $invoice = $this->renewalInvoice($service);

        $this->pay($invoice, 1000, 'PAY-1');

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertNotNull($invoice->paid_at);
        $this->assertSame('2026-12-01', $service->refresh()->next_due_date->toDateString());
        Event::assertDispatched(InvoicePaid::class);
    }

    public function test_partial_payments_add_up(): void
    {
        $service = Service::factory()->create(['recurring_amount' => 1000]);
        $invoice = $this->renewalInvoice($service);

        $this->pay($invoice, 400);
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->refresh()->status);
        $this->assertSame(600, $invoice->balance());

        $this->pay($invoice, 600);
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
    }

    public function test_overpayment_goes_to_credit_balance(): void
    {
        $service = Service::factory()->create(['recurring_amount' => 1000]);
        $invoice = $this->renewalInvoice($service);

        $this->pay($invoice, 1250);

        $this->assertSame(1000, $invoice->transactions()->sum('amount'));
        $this->assertSame(250, $service->client->refresh()->credit_balance);
    }

    public function test_repeated_gateway_reference_is_ignored(): void
    {
        $service = Service::factory()->create(['recurring_amount' => 1000]);
        $invoice = $this->renewalInvoice($service);

        $first = $this->pay($invoice, 500, 'PAY-1');
        $second = $this->pay($invoice, 500, 'PAY-1');

        $this->assertTrue($first->is($second));
        $this->assertSame(500, $invoice->balance());
    }

    public function test_cannot_pay_a_paid_invoice(): void
    {
        $service = Service::factory()->create(['recurring_amount' => 1000]);
        $invoice = $this->renewalInvoice($service);
        $this->pay($invoice, 1000);

        $this->expectException(InvalidArgumentException::class);
        $this->pay($invoice, 1000);
    }

    public function test_payment_activates_pending_service(): void
    {
        Event::fake([ServiceActivated::class]);
        $service = Service::factory()->pending()->create(['next_due_date' => '2026-10-09', 'recurring_amount' => 1000]);
        $invoice = Invoice::create([
            'client_id' => $service->client_id,
            'currency' => 'USD',
            'issue_date' => '2026-10-09',
            'due_date' => '2026-10-09',
        ]);
        $invoice->items()->create([
            'service_id' => $service->id,
            'type' => InvoiceItem::TYPE_SERVICE,
            'description' => 'First month',
            'amount' => 1000,
            'period_start' => '2026-10-09',
            'period_end' => '2026-11-08',
        ]);
        $invoice->recalculate();

        $this->pay($invoice, 1000);

        $service->refresh();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame('2026-11-09', $service->next_due_date->toDateString());
        Event::assertDispatched(ServiceActivated::class);
    }

    public function test_payment_unsuspends_service_suspended_for_nonpayment(): void
    {
        Event::fake([ServiceUnsuspended::class]);
        $service = Service::factory()->create(['recurring_amount' => 1000]);
        $invoice = $this->renewalInvoice($service);
        app(ServiceLifecycle::class)->suspend($service, ServiceLifecycle::REASON_OVERDUE);

        $this->pay($invoice, 1000);

        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->assertNull($service->suspension_reason);
        Event::assertDispatched(ServiceUnsuspended::class);
    }

    public function test_payment_leaves_manually_suspended_service_alone(): void
    {
        $service = Service::factory()->create(['recurring_amount' => 1000]);
        $invoice = $this->renewalInvoice($service);
        app(ServiceLifecycle::class)->suspend($service, 'abuse');

        $this->pay($invoice, 1000);

        $this->assertSame(ServiceStatus::Suspended, $service->refresh()->status);
    }
}
