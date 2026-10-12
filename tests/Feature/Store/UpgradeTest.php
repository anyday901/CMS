<?php

namespace Tests\Feature\Store;

use App\Billing\InvoiceCanceller;
use App\Billing\PaymentRecorder;
use App\Billing\ServiceUpgrader;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\FulfillmentTask;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\Promotion;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\TaxRule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

class UpgradeTest extends TestCase
{
    use RefreshDatabase;

    private const MONTHLY = 'monthly';

    private const DUE_DATE = '2026-11-01';

    private const MONTH_END = '2026-01-31';

    private Client $client;

    private Product $small;

    private Product $large;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        // 21 of the 31 days from Oct 1 to Nov 1 are left.
        $this->travelTo(CarbonImmutable::parse('2026-10-11'));

        $this->small = Product::factory()->create(['name' => 'Small', 'module' => 'manual']);
        $this->small->prices()->create(['billing_cycle' => self::MONTHLY, 'currency' => 'USD', 'price' => 1000]);
        $this->large = Product::factory()->create(['name' => 'Large', 'module' => 'manual']);
        $this->large->prices()->create(['billing_cycle' => self::MONTHLY, 'currency' => 'USD', 'price' => 2000, 'setup_fee' => 900]);
        $this->small->upgrades()->attach($this->large);
        $this->large->upgrades()->attach($this->small);

        $this->client = Client::factory()->create();
        $this->service = Service::factory()->create([
            'client_id' => $this->client->id,
            'product_id' => $this->small->id,
            'recurring_amount' => 1000,
            'next_due_date' => self::DUE_DATE,
        ]);
    }

    public function test_quote_prorates_the_days_left(): void
    {
        $quote = app(ServiceUpgrader::class)->quote($this->service, $this->large);

        $this->assertSame([21, 31], [$quote->daysLeft, $quote->periodDays]);
        $this->assertSame(677, $quote->credit); // 10.00 x 21/31
        $this->assertSame(1355, $quote->charge); // 20.00 x 21/31
        $this->assertSame(678, $quote->net());
    }

    public function test_upgrade_is_invoiced_and_applied_when_paid(): void
    {
        $this->actingAs($this->client, 'client');
        $this->get(route('portal.services.show', $this->service))->assertSee('Upgrade or downgrade');
        $this->get(route('portal.services.upgrade', $this->service))->assertOk()->assertSee('Large')->assertSee('$20.00');
        $this->get(route('portal.services.upgrade.show', [$this->service, $this->large]))->assertOk()->assertSee('$6.78')->assertSee('Continue to payment');

        $this->post(route('portal.services.upgrade.store', [$this->service, $this->large]))->assertRedirect();

        $upgrade = ServiceUpgrade::sole();
        $invoice = $upgrade->invoice;
        $this->assertSame(678, $invoice->total); // No setup fee on a move.
        $this->assertSame(InvoiceItem::TYPE_UPGRADE, $invoice->items()->sole()->type);
        $this->assertSame($this->small->id, $this->service->refresh()->product_id);
        $this->get(route('portal.services.show', $this->service))->assertSee('Moving to Large');

        // A second move waits for this one.
        $this->expectsProblem(fn () => app(ServiceUpgrader::class)->request($this->service->refresh(), $this->large), 'unpaid invoice');

        app(PaymentRecorder::class)->record($invoice, 678, 'manual');

        $service = $this->service->refresh();
        $this->assertSame($this->large->id, $service->product_id);
        $this->assertSame(2000, $service->recurring_amount);
        $this->assertSame(self::DUE_DATE, $service->next_due_date->toDateString());
        $this->assertSame(ServiceUpgrade::APPLIED, $upgrade->refresh()->status);
        $this->assertSame('change_package', FulfillmentTask::sole()->action);
    }

    public function test_downgrade_is_applied_at_once_with_the_difference_as_credit(): void
    {
        $this->service->update(['product_id' => $this->large->id, 'recurring_amount' => 2000]);
        $this->actingAs($this->client, 'client');

        $this->post(route('portal.services.upgrade.store', [$this->service, $this->small]))
            ->assertRedirect(route('portal.services.show', $this->service));

        $this->assertSame($this->small->id, $this->service->refresh()->product_id);
        $this->assertSame(1000, $this->service->recurring_amount);
        $this->assertSame(678, $this->client->refresh()->credit_balance);
        $this->assertSame(0, Invoice::count());
        $this->assertSame(-678, ServiceUpgrade::sole()->prorated_amount);
    }

    public function test_downgrade_credit_includes_the_tax_paid_on_it(): void
    {
        TaxRule::create(['name' => 'VAT', 'rate' => 1000]);
        $this->service->update(['product_id' => $this->large->id, 'recurring_amount' => 2000]);

        app(ServiceUpgrader::class)->request($this->service, $this->small);

        $this->assertSame(746, $this->client->refresh()->credit_balance); // 6.78 + 10%
    }

    public function test_month_end_periods_use_the_billed_start(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-14'));
        $this->service->update(['next_due_date' => '2026-02-28']);
        $paid = Invoice::create(['client_id' => $this->client->id, 'status' => InvoiceStatus::Paid, 'currency' => 'USD', 'issue_date' => self::MONTH_END, 'due_date' => self::MONTH_END]);
        $paid->items()->create(['service_id' => $this->service->id, 'type' => InvoiceItem::TYPE_SERVICE, 'description' => 'Small', 'amount' => 1000, 'period_start' => self::MONTH_END, 'period_end' => '2026-02-27']);

        $quote = app(ServiceUpgrader::class)->quote($this->service, $this->large);

        $this->assertSame([14, 28], [$quote->daysLeft, $quote->periodDays]);
        $this->assertSame(500, $quote->credit);
    }

    public function test_cancelling_the_invoice_drops_the_pending_move(): void
    {
        $upgrade = app(ServiceUpgrader::class)->request($this->service, $this->large);
        app(InvoiceCanceller::class)->cancel($upgrade->invoice);

        $this->assertSame(ServiceUpgrade::CANCELLED, $upgrade->refresh()->status);
        $this->actingAs($this->client, 'client');
        $this->get(route('portal.services.show', $this->service))->assertDontSee('Moving to');
        $this->assertNull(app(ServiceUpgrader::class)->problem($this->service->refresh()));
    }

    public function test_options_of_the_new_product_are_priced_in(): void
    {
        $ram = $this->large->options()->create(['name' => 'RAM', 'type' => ProductOption::DROPDOWN]);
        $ram->choices()->create(['name' => '4 GB']);
        $eight = $ram->choices()->create(['name' => '8 GB', 'prices' => [self::MONTHLY => 1100], 'sort_order' => 1]);
        $this->actingAs($this->client, 'client');

        $this->get(route('portal.services.upgrade.show', [$this->service, $this->large]))->assertSee('See the price')->assertDontSee('Continue to payment');
        $this->get(route('portal.services.upgrade.show', [$this->service, $this->large]).'?options['.$ram->id.']='.$eight->id)->assertSee('$31.00');

        $upgrade = app(ServiceUpgrader::class)->request($this->service, $this->large, [$ram->id => $eight->id]);
        app(PaymentRecorder::class)->record($upgrade->invoice, $upgrade->invoice->total, 'manual');

        $this->assertSame(3100, $this->service->refresh()->recurring_amount);
        $this->assertSame('RAM: 8 GB', $this->service->optionSummary());
    }

    public function test_recurring_promotion_carries_over_when_it_covers_the_new_product(): void
    {
        $promotion = Promotion::create(['code' => 'TENTH', 'type' => Promotion::PERCENT, 'value' => 1000, 'recurring' => true]);
        $this->service->update(['promotion_id' => $promotion->id, 'recurring_discount' => 100]);

        $quote = app(ServiceUpgrader::class)->quote($this->service, $this->large);
        $this->assertSame(200, $quote->recurringDiscount);
        $this->assertSame(610, $quote->credit); // 9.00 x 21/31
        $this->assertSame(1219, $quote->charge); // 18.00 x 21/31

        $promotion->update(['product_ids' => [$this->small->id]]);
        $this->assertSame(0, app(ServiceUpgrader::class)->quote($this->service->refresh(), $this->large)->recurringDiscount);
    }

    public function test_moves_that_are_not_allowed(): void
    {
        $other = Product::factory()->create();
        $other->prices()->create(['billing_cycle' => self::MONTHLY, 'currency' => 'USD', 'price' => 100]);
        $upgrader = app(ServiceUpgrader::class);

        $this->expectsProblem(fn () => $upgrader->quote($this->service, $other), "can't be moved");

        $this->service->update(['status' => 'suspended']);
        $this->expectsProblem(fn () => $upgrader->quote($this->service, $this->large), 'Only active');

        $this->service->update(['status' => 'active', 'next_due_date' => '2026-10-11']);
        $this->expectsProblem(fn () => $upgrader->quote($this->service, $this->large), 'renewal');

        $this->service->update(['next_due_date' => self::DUE_DATE]);
        $renewal = Invoice::create(['client_id' => $this->client->id, 'status' => InvoiceStatus::Unpaid, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => self::DUE_DATE]);
        $renewal->items()->create(['service_id' => $this->service->id, 'type' => InvoiceItem::TYPE_SERVICE, 'description' => 'Renewal', 'amount' => 1000]);
        $this->expectsProblem(fn () => $upgrader->quote($this->service, $this->large), 'unpaid invoice');

        // Someone else's service isn't reachable at all.
        $this->actingAs(Client::factory()->create(), 'client');
        $this->get(route('portal.services.upgrade', $this->service))->assertNotFound();
    }

    private function expectsProblem(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail("Expected: {$message}");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
