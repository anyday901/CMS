<?php

namespace Tests\Feature\Store;

use App\Billing\InvoiceGenerator;
use App\Billing\OrderService;
use App\Billing\PaymentRecorder;
use App\Billing\ServiceUpgrader;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

class BillingDayAndTrialTest extends TestCase
{
    use RefreshDatabase;

    private const MONTHLY = 'monthly';

    private const CHECKOUT = '/checkout';

    private const MANUAL = 'manual';

    private const TRIAL_END = '2026-10-26';

    private Client $client;

    private Product $vps;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-12'));

        $this->client = Client::factory()->create();
        $this->vps = $this->product('VPS', 3100);
    }

    private function product(string $name, int $monthly, array $attributes = []): Product
    {
        $product = Product::factory()->create(['name' => $name, ...$attributes]);
        $product->prices()->create(['billing_cycle' => self::MONTHLY, 'currency' => 'USD', 'price' => $monthly]);

        return $product;
    }

    private function billOnDay(int $day, ?int $extendFrom = null): void
    {
        $this->vps->update(['prorata_day' => $day, 'prorata_extend_day' => $extendFrom]);
    }

    private function withTrial(int $days): void
    {
        $this->vps->update(['trial_days' => $days]);
    }

    private function addToCart(array $data = [], ?Product $product = null): void
    {
        $this->actingAs($this->client, 'client');
        $this->post(route('cart.store', $product ?? $this->vps), ['billing_cycle' => self::MONTHLY, ...$data])->assertSessionHasNoErrors();
    }

    private function order(?Product $product = null, array $data = []): Invoice
    {
        $this->addToCart($data, $product);
        $this->post(self::CHECKOUT)->assertRedirect();

        return $this->client->invoices()->latest('id')->first();
    }

    private function serviceLine(Invoice $invoice, ?Service $service = null): InvoiceItem
    {
        return $invoice->items()->where('type', InvoiceItem::TYPE_SERVICE)
            ->when($service, fn ($items) => $items->where('service_id', $service->id))
            ->sole();
    }

    public function test_first_invoice_is_prorated_to_the_billing_day_and_renewals_fall_on_it(): void
    {
        $this->billOnDay(1);

        $this->addToCart();
        $this->get('/cart')->assertSee('Prorated to Nov 1, 2026')->assertSee('$20.00');
        $this->post(self::CHECKOUT)->assertRedirect();

        $invoice = Invoice::sole();
        $line = $this->serviceLine($invoice);
        // 20 of October's 31 days.
        $this->assertSame(2000, $invoice->total);
        $this->assertSame('Prorated: VPS (10/12/2026 - 10/31/2026)', $line->description);
        $this->assertSame('2026-10-31', $line->period_end->toDateString());

        app(PaymentRecorder::class)->record($invoice, 2000, self::MANUAL);
        $service = Service::sole();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame('2026-11-01', $service->next_due_date->toDateString());

        $renewal = app(InvoiceGenerator::class)->generate(CarbonImmutable::parse('2026-10-25'))->sole();
        $this->assertSame(3100, $renewal->total);
        $this->assertSame('2026-11-30', $this->serviceLine($renewal)->period_end->toDateString());
    }

    public function test_late_orders_also_pay_the_next_whole_period(): void
    {
        $this->billOnDay(1, 20);
        $this->travelTo(CarbonImmutable::parse('2026-10-25'));

        $invoice = $this->order();

        // 7 of 31 days, plus November.
        $this->assertSame(700 + 3100, $invoice->total);
        $this->assertSame('2026-11-30', $this->serviceLine($invoice)->period_end->toDateString());
        app(PaymentRecorder::class)->record($invoice, 3800, self::MANUAL);
        $this->assertSame('2026-12-01', Service::sole()->next_due_date->toDateString());
    }

    public function test_orders_on_the_billing_day_pay_a_whole_cycle(): void
    {
        $this->billOnDay(12, 1);

        $invoice = $this->order();

        $this->assertSame(3100, $invoice->total);
        $this->assertSame('VPS (10/12/2026 - 11/11/2026)', $this->serviceLine($invoice)->description);
    }

    public function test_add_ons_follow_the_billing_day_of_their_product(): void
    {
        $this->billOnDay(1);
        $backups = $this->product('Backups', 310, ['is_addon' => true]);
        $this->vps->addons()->attach($backups);

        $invoice = $this->order(data: ['addons' => [$backups->id]]);

        $addon = Service::where('product_id', $backups->id)->sole();
        $this->assertSame(200, $this->serviceLine($invoice, $addon)->amount);
        app(PaymentRecorder::class)->record($invoice, $invoice->total, self::MANUAL);
        $this->assertSame('2026-11-01', $addon->refresh()->next_due_date->toDateString());
    }

    public function test_staff_added_services_are_prorated_too(): void
    {
        $this->billOnDay(1);

        ['invoice' => $invoice] = app(OrderService::class)->create($this->client, $this->vps, BillingCycle::Monthly, today());

        $this->assertSame(2000, $invoice->total);
    }

    public function test_an_upgrade_in_a_prorated_first_period_is_worked_out_over_the_whole_cycle(): void
    {
        $this->billOnDay(1);
        $bigger = $this->product('Bigger VPS', 6200);
        $this->vps->upgrades()->attach($bigger);
        app(PaymentRecorder::class)->record($this->order(), 2000, self::MANUAL);

        $quote = app(ServiceUpgrader::class)->quote(Service::sole(), $bigger);

        $this->assertSame([20, 31], [$quote->daysLeft, $quote->periodDays]);
        $this->assertSame(4000 - 2000, $quote->net());
    }

    public function test_free_trial_starts_the_service_and_bills_when_it_ends(): void
    {
        $this->withTrial(14);
        $this->get(route('store.product', $this->vps))->assertSee('Free for 14 days.');

        $this->addToCart();
        $this->get('/cart')->assertSee('Free until Oct 26, 2026')->assertSee('$0.00');
        $this->post(self::CHECKOUT)->assertRedirect(route('portal.services.index'));

        $invoice = Invoice::sole();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame('Free trial: VPS (10/12/2026 - 10/25/2026)', $this->serviceLine($invoice)->description);
        $service = Service::sole();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame(self::TRIAL_END, $service->next_due_date->toDateString());
        $this->assertSame(self::TRIAL_END, $service->trial_ends_on->toDateString());
        $this->assertTrue($service->inTrial());
        $this->get(route('portal.services.show', $service))->assertSee('Free trial until then');

        $renewal = app(InvoiceGenerator::class)->generate(CarbonImmutable::parse('2026-10-19'))->sole();
        $this->assertSame(3100, $renewal->total);
        $this->assertSame(self::TRIAL_END, $renewal->due_date->toDateString());
    }

    public function test_a_client_gets_one_trial_per_product(): void
    {
        $this->withTrial(14);
        $this->order();

        $second = $this->order();

        $this->assertSame(3100, $second->total);
        $this->assertNull(Service::latest('id')->first()->trial_ends_on);
    }

    public function test_setup_fees_are_still_due_with_a_trial(): void
    {
        $this->withTrial(7);
        $this->vps->prices()->update(['setup_fee' => 500]);

        $invoice = $this->order();

        $this->assertSame(500, $invoice->total);
        $this->assertSame(ServiceStatus::Pending, Service::sole()->status);
        app(PaymentRecorder::class)->record($invoice, 500, self::MANUAL);
        $service = Service::sole();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame('2026-10-19', $service->next_due_date->toDateString());
    }

    public function test_services_in_a_trial_cannot_be_upgraded_yet(): void
    {
        $this->withTrial(14);
        $this->order();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('once the free trial ends');
        app(ServiceUpgrader::class)->quote(Service::sole(), Product::factory()->create());
    }

    public function test_staff_set_billing_day_trial_and_stock_on_the_product_form(): void
    {
        $this->actingAs(User::factory()->create());

        $this->put(route('admin.products.update', $this->vps), [
            'name' => 'VPS', 'active' => '1', 'prices' => [self::MONTHLY => ['price' => '31.00']],
            'prorata_day' => '1', 'prorata_extend_day' => '25', 'trial_days' => '30', 'stock_control' => '1', 'stock_quantity' => '5',
        ])->assertSessionHasNoErrors();

        $this->vps->refresh();
        $this->assertSame([1, 25, 30, true, 5], [$this->vps->prorata_day, $this->vps->prorata_extend_day, $this->vps->trial_days, $this->vps->stock_control, $this->vps->stock_quantity]);
        $this->put(route('admin.products.update', $this->vps), ['name' => 'VPS', 'prorata_day' => '29'])->assertSessionHasErrors('prorata_day');
        $this->get(route('admin.products.edit', $this->vps))->assertOk()->assertSee('Free trial days');
    }
}
