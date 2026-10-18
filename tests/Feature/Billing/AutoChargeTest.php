<?php

namespace Tests\Feature\Billing;

use App\Billing\AutoCharger;
use App\Billing\PaymentRecorder;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutoChargeTest extends TestCase
{
    use RefreshDatabase;

    private const DECLINE_MESSAGE = 'Your card has insufficient funds.';

    private const DUE_DATE = '2026-11-11';

    private const DAY_AFTER_DUE = '2026-11-12';

    private const AUTOMATION_URL = '/admin/settings/automation';

    private const DUE = '2026-11-10';

    private const STRIPE = 'api.stripe.com/v1/payment_intents';

    private Client $client;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'payments.stripe' => ['publishable_key' => 'pk_test_abc', 'secret_key' => 'sk_test_abc', 'webhook_secret' => 'whsec_test', 'api_version' => '2024-06-20'],
            'billing.auto_charge_days_before_due' => 0,
            'billing.charge_retry_days' => [1, 3, 5],
        ]);
        $this->travelTo(Carbon::parse(self::DUE)->setTime(0, 5));

        $this->client = Client::factory()->create();
        PaymentMethod::create([
            'client_id' => $this->client->id, 'gateway' => 'stripe', 'gateway_customer_id' => 'cus_1', 'gateway_reference' => 'pm_1',
            'brand' => 'visa', 'last4' => '4242', 'exp_month' => 4, 'exp_year' => 2030, 'is_default' => true,
        ]);
        $this->invoice = Invoice::create(['client_id' => $this->client->id, 'currency' => 'USD', 'issue_date' => today()->subDays(7), 'due_date' => self::DUE]);
        $this->invoice->items()->create(['description' => 'Hosting', 'amount' => 2500]);
        $this->invoice->recalculate();
    }

    private function succeeds(): void
    {
        Http::fake([self::STRIPE => Http::response([
            'id' => 'pi_auto', 'status' => 'succeeded', 'amount_received' => 2500, 'currency' => 'usd',
            'metadata' => ['invoice_id' => (string) $this->invoice->id],
            'latest_charge' => ['balance_transaction' => ['fee' => 103, 'currency' => 'usd']],
        ])]);
    }

    private function declines(): void
    {
        Http::fake([self::STRIPE => Http::response(['error' => [
            'type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds', 'message' => self::DECLINE_MESSAGE,
        ]], 402)]);
    }

    private function runOn(string $date): array
    {
        $this->travelTo(Carbon::parse($date)->setTime(0, 5));

        return app(AutoCharger::class)->run(today());
    }

    public function test_the_billing_run_charges_the_default_card_on_the_due_date(): void
    {
        $this->succeeds();

        $this->artisan('billing:run')->expectsOutputToContain('Charged saved cards: 1 paid, 0 failed, 0 processing.')->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r['payment_method'] === 'pm_1' && $r['customer'] === 'cus_1' && $r['off_session'] === 'true'
            && $r['amount'] === 2500 && $r->hasHeader('Idempotency-Key'));
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->refresh()->status);
        $attempt = PaymentAttempt::sole();
        $this->assertSame([PaymentAttempt::SUCCEEDED, PaymentAttempt::AUTO, 'Visa ending 4242'], [$attempt->status, $attempt->source, $attempt->method_label]);
        $this->assertSame(103, $attempt->transaction->fee);
        // The usual receipt goes out.
        $this->assertTrue(EmailLog::where('template', 'payment_receipt')->where('invoice_id', $this->invoice->id)->exists());
    }

    public function test_invoices_are_not_charged_before_they_are_due_unless_set_to(): void
    {
        $this->succeeds();
        $this->travelTo(Carbon::parse(self::DUE)->subDays(2));

        app(AutoCharger::class)->run(today());
        Http::assertNothingSent();

        config(['billing.auto_charge_days_before_due' => 2]);
        app(AutoCharger::class)->run(today());
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->refresh()->status);
    }

    public function test_a_failed_charge_is_retried_on_the_retry_days_and_never_twice_a_day(): void
    {
        $this->declines();

        $this->assertSame(1, $this->runOn(self::DUE)[PaymentAttempt::FAILED]);
        $this->assertSame(0, $this->runOn(self::DUE)[PaymentAttempt::FAILED]);
        $this->assertSame(1, $this->runOn(self::DUE_DATE)[PaymentAttempt::FAILED]);
        $this->assertSame(0, $this->runOn(self::DAY_AFTER_DUE)[PaymentAttempt::FAILED]);
        $this->assertSame(1, $this->runOn('2026-11-13')[PaymentAttempt::FAILED]);
        $this->assertSame(0, $this->runOn('2026-11-14')[PaymentAttempt::FAILED]);
        $this->assertSame(1, $this->runOn('2026-11-15')[PaymentAttempt::FAILED]);
        $this->assertSame(0, $this->runOn('2026-11-16')[PaymentAttempt::FAILED]);
        $this->assertSame(0, $this->runOn('2026-11-30')[PaymentAttempt::FAILED]);

        $this->assertSame(['2026-11-10', self::DUE_DATE, '2026-11-13', '2026-11-15'], PaymentAttempt::orderBy('id')->get()->map(fn ($a) => $a->attempted_on->toDateString())->all());
        Http::assertSentCount(4);
        $this->assertSame(InvoiceStatus::Unpaid, $this->invoice->refresh()->status);
    }

    public function test_a_missed_night_catches_up_with_one_charge(): void
    {
        $this->declines();

        // Nothing ran on the due date or the day after.
        $this->assertSame(1, $this->runOn(self::DAY_AFTER_DUE)[PaymentAttempt::FAILED]);
        $this->assertSame(0, $this->runOn(self::DAY_AFTER_DUE)[PaymentAttempt::FAILED]);
        Http::assertSentCount(1);
    }

    public function test_a_failed_charge_emails_the_client_and_shows_on_the_invoice(): void
    {
        $this->declines();
        $this->runOn(self::DUE);

        $email = EmailLog::where('template', 'payment_failed')->sole();
        $this->assertSame($this->invoice->id, $email->invoice_id);
        $this->assertStringContainsString('Visa ending 4242', $email->body);
        $this->assertStringContainsString(self::DECLINE_MESSAGE, $email->body);
        $this->assertStringContainsString(route('portal.payment-methods.index'), $email->body);

        $this->actingAs(User::factory()->create(), 'web');
        $this->get("/admin/invoices/{$this->invoice->id}")->assertSee('Saved card charges')->assertSee('Billing run')->assertSee(self::DECLINE_MESSAGE);
    }

    public function test_clients_who_opted_out_are_not_charged(): void
    {
        $this->succeeds();
        $this->client->forceFill(['auto_charge' => false])->save();

        $this->runOn(self::DUE);

        Http::assertNothingSent();
        $this->assertSame(0, PaymentAttempt::count());
    }

    public function test_nothing_is_charged_without_a_gateway_or_a_default_card(): void
    {
        $this->succeeds();
        config(['payments.stripe.secret_key' => null]);
        $this->runOn(self::DUE);
        Http::assertNothingSent();

        config(['payments.stripe.secret_key' => 'sk_test_abc']);
        PaymentMethod::query()->update(['is_default' => false]);
        $this->runOn(self::DUE);
        Http::assertNothingSent();
    }

    public function test_staff_set_the_charge_and_retry_days_under_automation(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $form = [
            'run_time' => '00:05', 'invoice_days_before_due' => '7', 'late_fee_type' => 'fixed', 'overage_due_days' => '7',
            'auto_charge_days_before_due' => '2', 'charge_retry_days' => '7, 2, 2',
        ];

        $this->get(self::AUTOMATION_URL)->assertSee('Automatic card charging')->assertSee('value="1, 3, 5"', false);
        $this->put(self::AUTOMATION_URL, ['charge_retry_days' => '1, soon'] + $form)->assertSessionHasErrors('charge_retry_days');
        $this->put(self::AUTOMATION_URL, $form)->assertSessionHasNoErrors();

        Settings::apply();
        $this->assertSame([2, [2, 7]], [(int) config('billing.auto_charge_days_before_due'), config('billing.charge_retry_days')]);
        $this->assertSame([-2, 2, 7], AutoCharger::tries()->all());
    }

    public function test_a_charge_still_processing_is_settled_when_the_invoice_is_paid(): void
    {
        Http::fake([self::STRIPE => Http::response(['id' => 'pi_auto', 'status' => 'processing', 'currency' => 'usd', 'metadata' => ['invoice_id' => (string) $this->invoice->id]])]);

        $this->assertSame(1, $this->runOn(self::DUE)[PaymentAttempt::PENDING]);
        // Not tried again on a retry day while the first charge may still go through.
        $this->runOn(self::DUE_DATE);
        Http::assertSentCount(1);

        // The gateway's webhook records the payment.
        app(PaymentRecorder::class)->record($this->invoice, 2500, 'stripe', 'pi_auto');
        $this->assertSame(PaymentAttempt::SUCCEEDED, PaymentAttempt::sole()->status);
    }

    public function test_a_payment_made_another_way_leaves_a_processing_charge_pending(): void
    {
        Http::fake([self::STRIPE => Http::response(['id' => 'pi_auto', 'status' => 'processing', 'currency' => 'usd', 'metadata' => ['invoice_id' => (string) $this->invoice->id]])]);
        $this->runOn(self::DUE);

        // Paid by bank transfer while the card charge is still processing.
        app(PaymentRecorder::class)->record($this->invoice, 2500, 'bank_transfer', 'BT-1');

        $attempt = PaymentAttempt::sole();
        $this->assertSame([PaymentAttempt::PENDING, 'pi_auto'], [$attempt->status, $attempt->gateway_reference]);
    }

    public function test_long_overdue_invoices_are_not_charged_when_auto_charging_is_turned_on(): void
    {
        $this->succeeds();
        $old = Invoice::create(['client_id' => $this->client->id, 'currency' => 'USD', 'issue_date' => '2026-01-01', 'due_date' => '2026-01-10']);
        $old->items()->create(['description' => 'Hosting', 'amount' => 900]);
        $old->recalculate();

        $this->runOn(self::DUE);

        $this->assertSame(InvoiceStatus::Unpaid, $old->refresh()->status);
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->refresh()->status);
        Http::assertSentCount(1);
    }
}
