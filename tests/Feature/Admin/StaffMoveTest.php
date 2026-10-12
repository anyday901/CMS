<?php

namespace Tests\Feature\Admin;

use App\Billing\PaymentRecorder;
use App\Billing\ServiceUpgrader;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\FulfillmentTask;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StaffMoveTest extends TestCase
{
    use RefreshDatabase;

    private const MONTHLY = 'monthly';

    private const ANNUALLY = 'annually';

    private const DUE_DATE = '2026-11-01';

    private const MOVE_ROUTE = 'admin.services.move';

    private Client $client;

    private Product $small;

    private Product $large;

    private Service $service;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        // 21 of the 31 days from Oct 1 to Nov 1 are left.
        $this->travelTo(CarbonImmutable::parse('2026-10-11'));

        $this->small = Product::factory()->create(['name' => 'Small', 'module' => 'manual']);
        $this->small->prices()->create(['billing_cycle' => self::MONTHLY, 'currency' => 'USD', 'price' => 1000]);
        $this->small->prices()->create(['billing_cycle' => self::ANNUALLY, 'currency' => 'USD', 'price' => 10000]);
        // Not set as an upgrade of Small: only staff can move there.
        $this->large = Product::factory()->create(['name' => 'Large', 'module' => 'manual']);
        $this->large->prices()->create(['billing_cycle' => self::MONTHLY, 'currency' => 'USD', 'price' => 2000]);
        $this->large->prices()->create(['billing_cycle' => self::ANNUALLY, 'currency' => 'USD', 'price' => 20000]);

        $this->client = Client::factory()->create();
        $this->service = Service::factory()->create([
            'client_id' => $this->client->id,
            'product_id' => $this->small->id,
            'recurring_amount' => 1000,
            'next_due_date' => self::DUE_DATE,
        ]);
        $this->staff = User::factory()->create(['name' => 'Grace']);
        $this->actingAs($this->staff);
    }

    private function move(Product $product, string $cycle, bool $charge): ServiceUpgrade
    {
        return app(ServiceUpgrader::class)->staffMove($this->service->refresh(), $product, BillingCycle::from($cycle), [], $charge, $this->staff);
    }

    public function test_staff_upgrade_from_the_service_page_is_invoiced_and_applied_when_paid(): void
    {
        $this->get(route('admin.services.show', $this->service))->assertSee('Upgrade or downgrade');
        $this->get(route(self::MOVE_ROUTE, $this->service))->assertOk()->assertSee('Large: $20.00 monthly');
        $this->get(route(self::MOVE_ROUTE, [$this->service, 'product_id' => $this->large->id, 'billing_cycle' => self::MONTHLY, 'charge' => 1]))
            ->assertOk()->assertSee('$6.78')->assertSee('Invoice the move');

        $this->post(route('admin.services.move.store', $this->service), ['product_id' => $this->large->id, 'billing_cycle' => self::MONTHLY, 'charge' => 1])
            ->assertRedirect();

        $upgrade = ServiceUpgrade::sole();
        $this->assertSame([678, $this->staff->id], [$upgrade->invoice->total, $upgrade->moved_by]);
        $this->assertSame($this->small->id, $this->service->refresh()->product_id);

        app(PaymentRecorder::class)->record($upgrade->invoice, 678, 'manual');

        $this->assertSame([$this->large->id, 2000, self::DUE_DATE], [$this->service->refresh()->product_id, $this->service->recurring_amount, $this->service->next_due_date->toDateString()]);
        $this->assertSame('change_package', FulfillmentTask::sole()->action);
    }

    public function test_a_charged_cycle_change_bills_a_new_period_from_today(): void
    {
        $upgrade = $this->move($this->large, self::ANNUALLY, true);

        // A year of Large less the 21 unused days of Small.
        $this->assertSame(20000 - 677, $upgrade->invoice->total);
        $this->assertStringContainsString('Large, annually (10/11/2026 - 10/10/2027', $upgrade->invoice->items()->sole()->description);

        app(PaymentRecorder::class)->record($upgrade->invoice, $upgrade->invoice->total, 'manual');

        $service = $this->service->refresh();
        $this->assertSame(BillingCycle::Annually, $service->billing_cycle);
        $this->assertSame('2027-10-11', $service->next_due_date->toDateString());
        $this->assertSame(20000, $service->recurring_amount);
    }

    public function test_a_charged_cycle_change_that_costs_less_is_credited_at_once(): void
    {
        $this->service->update(['product_id' => $this->large->id, 'billing_cycle' => self::ANNUALLY, 'recurring_amount' => 20000, 'next_due_date' => '2027-04-11']);

        $this->move($this->small, self::MONTHLY, true);

        $service = $this->service->refresh();
        $this->assertSame([BillingCycle::Monthly, '2026-11-11', 1000], [$service->billing_cycle, $service->next_due_date->toDateString(), $service->recurring_amount]);
        // 182 of 365 days of 200.00 unused, less a month of Small.
        $this->assertSame(9973 - 1000, $this->client->refresh()->credit_balance);
        $this->assertSame(0, Invoice::count());
    }

    public function test_moving_without_charging_changes_the_price_from_the_next_renewal(): void
    {
        $renewal = Invoice::create(['client_id' => $this->client->id, 'status' => InvoiceStatus::Unpaid, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => self::DUE_DATE]);
        $renewal->items()->create(['service_id' => $this->service->id, 'type' => InvoiceItem::TYPE_SERVICE, 'description' => 'Renewal', 'amount' => 1000]);
        $this->service->update(['status' => 'suspended']);

        $this->post(route('admin.services.move.store', $this->service), ['product_id' => $this->large->id, 'billing_cycle' => self::ANNUALLY, 'charge' => 0])
            ->assertRedirect(route('admin.services.show', $this->service));

        $service = $this->service->refresh();
        $this->assertSame([$this->large->id, BillingCycle::Annually, 20000, self::DUE_DATE], [$service->product_id, $service->billing_cycle, $service->recurring_amount, $service->next_due_date->toDateString()]);
        $this->assertSame([0, 0], [$this->client->refresh()->credit_balance, ServiceUpgrade::sole()->prorated_amount]);
        $this->assertSame(1, Invoice::count());
    }

    public function test_a_cycle_change_alone_does_not_touch_the_module(): void
    {
        $this->move($this->small, self::ANNUALLY, false);

        $this->assertSame(BillingCycle::Annually, $this->service->refresh()->billing_cycle);
        $this->assertSame(0, FulfillmentTask::count());
    }

    public function test_moves_staff_cannot_make(): void
    {
        $this->expectsProblem(fn () => $this->move($this->small, self::MONTHLY, false), 'different product or billing cycle');
        $this->expectsProblem(fn () => $this->move($this->large, 'one_time', false), 'recurring billing cycle');

        // A charged move waiting on its invoice blocks one without charging.
        $this->move($this->large, self::MONTHLY, true);
        $this->expectsProblem(fn () => $this->move($this->large, self::ANNUALLY, false), 'waiting on its invoice');
        $this->expectsProblem(fn () => $this->move($this->large, self::ANNUALLY, true), 'unpaid invoice');

        $this->service->update(['status' => 'terminated']);
        $this->expectsProblem(fn () => $this->move($this->large, self::MONTHLY, false), 'Only active or suspended');
        $this->assertSame($this->small->id, $this->service->refresh()->product_id);
    }
}
