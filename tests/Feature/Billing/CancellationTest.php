<?php

namespace Tests\Feature\Billing;

use App\Billing\InvoiceGenerator;
use App\Billing\ServiceCanceller;
use App\Billing\ServiceUpgrader;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\CancellationRequest;
use App\Models\Client;
use App\Models\FulfillmentTask;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CancellationTest extends TestCase
{
    use RefreshDatabase;

    private const DUE_DATE = '2026-11-01';

    private const CANCEL_ROUTE = 'portal.services.cancel';

    private const SHOW_ROUTE = 'portal.services.show';

    private Client $client;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-12'));

        $this->client = Client::factory()->create();
        $product = Product::factory()->create(['name' => 'VPS', 'module' => 'manual']);
        $this->service = Service::factory()->create([
            'client_id' => $this->client->id,
            'product_id' => $product->id,
            'next_due_date' => self::DUE_DATE,
        ]);
    }

    private function unpaidInvoice(Service ...$services): Invoice
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'status' => InvoiceStatus::Unpaid, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => self::DUE_DATE]);
        foreach ($services as $service) {
            $invoice->items()->create(['service_id' => $service->id, 'type' => InvoiceItem::TYPE_SERVICE, 'description' => 'Renewal', 'amount' => 1000, 'period_start' => self::DUE_DATE, 'period_end' => '2026-11-30']);
        }

        return $invoice->recalculate();
    }

    public function test_end_of_period_request_stops_renewals_and_cancels_on_the_due_date(): void
    {
        $renewal = $this->unpaidInvoice($this->service);
        $this->actingAs($this->client, 'client');
        $this->get(route(self::SHOW_ROUTE, $this->service))->assertSee('Cancel this service')->assertSee('Nov 1, 2026');

        $this->post(route(self::CANCEL_ROUTE, $this->service), ['type' => 'end_of_period', 'reason' => 'Moving on'])
            ->assertRedirect(route(self::SHOW_ROUTE, $this->service));

        $request = CancellationRequest::sole();
        $this->assertSame([CancellationRequest::PENDING, self::DUE_DATE, 'Moving on'], [$request->status, $request->cancel_on->toDateString(), $request->reason]);
        $this->assertSame(InvoiceStatus::Cancelled, $renewal->refresh()->status);
        $this->assertSame(ServiceStatus::Active, $this->service->refresh()->status);
        $this->get(route(self::SHOW_ROUTE, $this->service))->assertSee('will be cancelled on Nov 1, 2026')->assertDontSee('Cancel this service');

        // The billing run doesn't invoice it again, and cancels it on the day.
        $this->assertCount(0, app(InvoiceGenerator::class)->generate(today()));
        $this->travelTo(CarbonImmutable::parse(self::DUE_DATE));
        $this->artisan('billing:run')->expectsOutputToContain('Cancelled 1 service(s)')->assertSuccessful();

        $this->assertSame(ServiceStatus::Cancelled, $this->service->refresh()->status);
        $this->assertSame(CancellationRequest::COMPLETED, $request->refresh()->status);
        $this->assertSame('terminate', FulfillmentTask::sole()->action);
    }

    public function test_immediate_request_cancels_the_service_and_its_add_ons_now(): void
    {
        $addon = Service::factory()->create(['client_id' => $this->client->id, 'parent_service_id' => $this->service->id, 'next_due_date' => self::DUE_DATE]);
        $this->actingAs($this->client, 'client');

        $this->post(route(self::CANCEL_ROUTE, $this->service), ['type' => 'immediate'])->assertSessionHasNoErrors();

        $this->assertSame(ServiceStatus::Cancelled, $this->service->refresh()->status);
        $this->assertSame(ServiceStatus::Cancelled, $addon->refresh()->status);
        $this->assertSame(CancellationRequest::COMPLETED, CancellationRequest::sole()->status);
        $this->post(route(self::CANCEL_ROUTE, $this->service), ['type' => 'immediate'])->assertSessionHasErrors('cancellation');
    }

    public function test_shared_invoices_lose_only_the_cancelled_service_lines(): void
    {
        $other = Service::factory()->create(['client_id' => $this->client->id, 'next_due_date' => self::DUE_DATE]);
        $shared = $this->unpaidInvoice($this->service, $other);

        app(ServiceCanceller::class)->request($this->service, CancellationRequest::END_OF_PERIOD);

        $shared->refresh();
        $this->assertSame(InvoiceStatus::Unpaid, $shared->status);
        $this->assertSame(1000, $shared->total);
        $this->assertSame([$other->id], $shared->items()->pluck('service_id')->all());
    }

    public function test_withdrawn_requests_let_the_service_renew_again(): void
    {
        $this->actingAs($this->client, 'client');
        $this->post(route(self::CANCEL_ROUTE, $this->service), ['type' => 'end_of_period']);
        $this->assertNotNull(app(ServiceUpgrader::class)->problem($this->service->refresh()));

        $this->delete(route('portal.services.cancel.destroy', $this->service))->assertRedirect();

        $this->assertSame(CancellationRequest::WITHDRAWN, CancellationRequest::sole()->status);
        $this->assertCount(1, app(InvoiceGenerator::class)->generate(CarbonImmutable::parse('2026-10-25')));
        $this->travelTo(CarbonImmutable::parse(self::DUE_DATE));
        $this->artisan('billing:run')->assertSuccessful();
        $this->assertSame(ServiceStatus::Active, $this->service->refresh()->status);
    }

    public function test_staff_can_request_and_withdraw_from_the_admin_service_page(): void
    {
        $staff = User::factory()->create(['name' => 'Grace']);
        $this->actingAs($staff);
        $this->get(route('admin.services.show', $this->service))->assertSee('Request cancellation for the client');

        $this->post(route('admin.cancellations.store', $this->service), ['type' => 'end_of_period', 'reason' => 'Asked by phone'])->assertSessionHasNoErrors();

        $request = CancellationRequest::sole();
        $this->assertSame($staff->id, $request->requested_by);
        $this->get(route('admin.services.show', $this->service))->assertSee('Cancellation requested by Grace')->assertSee('Asked by phone');
        $this->get(route('admin.dashboard'))->assertSee('Upcoming cancellations');

        $this->delete(route('admin.cancellations.destroy', $request))->assertSessionHasNoErrors();
        $this->assertSame(CancellationRequest::WITHDRAWN, $request->refresh()->status);
    }

    public function test_requests_that_are_not_allowed(): void
    {
        $canceller = app(ServiceCanceller::class);
        $oneTime = Service::factory()->create(['client_id' => $this->client->id, 'billing_cycle' => BillingCycle::OneTime, 'next_due_date' => null]);

        $this->expectsProblem(fn () => $canceller->request($oneTime, CancellationRequest::END_OF_PERIOD), 'only be cancelled straight away');
        $this->expectsProblem(fn () => $canceller->request($this->service, 'someday'), 'Choose when');

        $this->service->update(['status' => ServiceStatus::Terminated]);
        $this->expectsProblem(fn () => $canceller->request($this->service, CancellationRequest::IMMEDIATE), 'Only active or suspended');
    }

    public function test_overdue_services_asking_for_end_of_period_go_now(): void
    {
        $this->service->update(['status' => ServiceStatus::Suspended, 'next_due_date' => '2026-10-01']);

        $request = app(ServiceCanceller::class)->request($this->service, CancellationRequest::END_OF_PERIOD);

        $this->assertSame('2026-10-12', $request->cancel_on->toDateString());
        $this->assertSame(ServiceStatus::Cancelled, $this->service->refresh()->status);
    }
}
