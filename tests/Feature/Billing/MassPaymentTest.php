<?php

namespace Tests\Feature\Billing;

use App\Billing\CreditApplier;
use App\Billing\InvoiceCanceller;
use App\Billing\InvoiceReminders;
use App\Billing\LateFeeProcessor;
use App\Billing\MassPayment;
use App\Billing\PaymentRecorder;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MassPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/portal/invoices/mass-pay';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Client::factory()->create();
        $this->actingAs($this->client, 'client');
    }

    private function invoice(int $amount, ?Client $client = null, string $due = '-3 days'): Invoice
    {
        $invoice = Invoice::create(['client_id' => ($client ?? $this->client)->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()->modify($due)]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => $amount]);

        return $invoice->recalculate();
    }

    /** @param  list<Invoice>  $invoices */
    private function massPay(array $invoices): Invoice
    {
        $this->post(self::URL, ['invoices' => collect($invoices)->pluck('id')->all()])->assertSessionHasNoErrors();

        return Invoice::whereHas('items', fn ($items) => $items->where('type', InvoiceItem::TYPE_MASS_PAY))->latest('id')->firstOrFail();
    }

    public function test_paying_a_mass_payment_pays_each_invoice_on_it(): void
    {
        $first = $this->invoice(1000);
        $second = $this->invoice(1500);
        $service = Service::factory()->for($this->client)->create(['status' => ServiceStatus::Suspended, 'suspension_reason' => 'overdue', 'next_due_date' => today()->subDays(3)]);
        $second->items()->first()->update(['service_id' => $service->id, 'type' => InvoiceItem::TYPE_SERVICE, 'period_start' => today()->subDays(3)]);
        // Part of the second is already paid; the mass payment asks only for the rest.
        app(PaymentRecorder::class)->record($second, 500, 'bank_transfer');

        $this->get('/portal/invoices')->assertSee('Pay selected invoices')->assertSee('form="mass-pay"', false);
        $response = $this->post(self::URL, ['invoices' => [$first->id, $second->id]]);

        $mass = Invoice::latest('id')->first();
        $response->assertRedirect("/portal/invoices/{$mass->id}");
        $this->assertTrue($mass->isMassPayment());
        $this->assertSame(2000, $mass->total);
        $this->assertSame(0, $mass->tax);
        $this->assertSame(today()->toDateString(), $mass->due_date->toDateString());
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $mass->items()->pluck('paid_invoice_id')->all());
        $this->get("/portal/invoices/{$first->id}")->assertSee("invoice #{$mass->number}");

        app(PaymentRecorder::class)->record($mass, 2000, 'stripe', 'pi_123');

        $this->assertSame(InvoiceStatus::Paid, $mass->refresh()->status);
        $this->assertSame(InvoiceStatus::Paid, $first->refresh()->status);
        $this->assertSame(InvoiceStatus::Paid, $second->refresh()->status);
        $this->assertSame(0, $second->balance());
        $this->assertSame(0, $this->client->refresh()->credit_balance);
        // Paying the original unsuspends its service, as a direct payment would.
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        // The money came in once: the gateway payment, then credit moved onto each invoice.
        $this->assertSame(2500, (int) Transaction::where('gateway', '!=', CreditApplier::GATEWAY)->sum('amount'));
        $this->assertSame(['credit'], $first->transactions()->pluck('gateway')->all());
    }

    public function test_cancelled_or_expired_mass_payments_leave_the_invoices_payable(): void
    {
        $first = $this->invoice(1000);
        $second = $this->invoice(2000);

        $mass = $this->massPay([$first, $second]);
        app(InvoiceCanceller::class)->cancel($mass);
        $this->assertSame(InvoiceStatus::Unpaid, $first->refresh()->status);
        $this->assertSame(1000, $first->balance());

        // A new one for the same invoices replaces the open one.
        $older = $this->massPay([$first, $second]);
        $newer = $this->massPay([$first, $second]);
        $this->assertSame(InvoiceStatus::Cancelled, $older->refresh()->status);
        $this->assertSame(InvoiceStatus::Unpaid, $newer->refresh()->status);

        $this->assertSame(0, app(MassPayment::class)->expire(today()->addDays(MassPayment::EXPIRES_AFTER_DAYS)));
        $this->assertSame(1, app(MassPayment::class)->expire(today()->addDays(MassPayment::EXPIRES_AFTER_DAYS + 1)));
        $this->assertSame(InvoiceStatus::Cancelled, $newer->refresh()->status);
        $this->assertSame(InvoiceStatus::Unpaid, $first->refresh()->status);
        $this->assertSame(InvoiceStatus::Unpaid, $second->refresh()->status);

        // Paying one of its invoices on its own makes a mass payment stale straight away.
        $stale = $this->massPay([$first, $second]);
        app(PaymentRecorder::class)->record($first, 1000, 'paypal');
        $this->artisan('billing:run')->assertSuccessful();
        $this->assertSame(InvoiceStatus::Cancelled, $stale->refresh()->status);
        $this->assertSame(InvoiceStatus::Unpaid, $second->refresh()->status);
    }

    public function test_an_invoice_paid_in_the_meantime_leaves_its_share_in_credit(): void
    {
        $first = $this->invoice(1000);
        $second = $this->invoice(2000);
        $mass = $this->massPay([$first, $second]);

        app(PaymentRecorder::class)->record($first, 1000, 'paypal');
        app(PaymentRecorder::class)->record($mass, 3000, 'paypal');

        $this->assertSame(InvoiceStatus::Paid, $second->refresh()->status);
        $this->assertSame(1000, $this->client->refresh()->credit_balance);
    }

    public function test_only_the_clients_own_unpaid_invoices_can_be_paid_together(): void
    {
        $first = $this->invoice(1000);
        $second = $this->invoice(2000);
        $someoneElses = $this->invoice(500, Client::factory()->create());
        $paid = $this->invoice(700);
        app(PaymentRecorder::class)->record($paid, 700, 'paypal');

        // One invoice is just paid on its own.
        $this->post(self::URL, ['invoices' => [$first->id]])->assertRedirect("/portal/invoices/{$first->id}");
        $this->post(self::URL, ['invoices' => [$first->id, $someoneElses->id]])->assertSessionHasErrors(['invoices' => 'Choose at least two unpaid invoices to pay together.']);
        $this->post(self::URL, ['invoices' => [$first->id, $paid->id]])->assertSessionHasErrors(['invoices' => 'Some of those invoices can no longer be paid together. Please choose again.']);

        // A mass payment can't go on another one.
        $mass = $this->massPay([$first, $second]);
        $this->post(self::URL, ['invoices' => [$first->id, $mass->id]])->assertSessionHasErrors('invoices');

        config(['billing.mass_pay' => false]);
        $this->get('/portal/invoices')->assertDontSee('Pay selected invoices');
        $this->post(self::URL, ['invoices' => [$first->id, $second->id]])->assertSessionHasErrors(['invoices' => 'Paying several invoices at once is not available.']);
    }

    public function test_mass_payments_are_not_chased_or_counted_as_owed(): void
    {
        config(['billing.late_fee_after_days' => 1, 'billing.late_fee_amount' => '5.00', 'billing.reminder_days' => [1]]);
        $first = $this->invoice(1000, due: '-30 days');
        $second = $this->invoice(2000, due: '-30 days');
        $mass = $this->massPay([$first, $second]);
        $mass->update(['due_date' => today()->subDays(2)]);

        app(LateFeeProcessor::class)->run(today());
        app(InvoiceReminders::class)->run(today());

        $this->assertSame(3000, $mass->refresh()->total);
        $this->assertFalse($mass->items()->where('type', InvoiceItem::TYPE_LATE_FEE)->exists());
        $this->assertSame(2, Invoice::where('status', InvoiceStatus::Unpaid)->withoutFunds()->count());
        $this->get('/portal')->assertSee('You have 2 unpaid invoices');
    }

    public function test_credit_can_pay_a_mass_payment(): void
    {
        $first = $this->invoice(1000);
        $second = $this->invoice(2000);
        $mass = $this->massPay([$first, $second]);
        $this->client->forceFill(['credit_balance' => 3000])->save();

        $this->post("/portal/invoices/{$mass->id}/credit")->assertRedirect();

        $this->assertSame(InvoiceStatus::Paid, $first->refresh()->status);
        $this->assertSame(InvoiceStatus::Paid, $second->refresh()->status);
        $this->assertSame(0, $this->client->refresh()->credit_balance);
    }
}
