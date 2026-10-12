<?php

namespace Tests\Feature\Admin;

use App\Billing\PaymentRecorder;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Enums\StaffRole;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OrderQueueTest extends TestCase
{
    use RefreshDatabase;

    private const MONTHLY = 'monthly';

    private const CHECKOUT = '/checkout';

    private const MANUAL = 'manual';

    private const FRAUD_BUTTON = 'Mark as fraud';

    private const INDEX = 'admin.orders.index';

    private const SHOW = 'admin.orders.show';

    private const STATUS = 'status';

    private const ERROR_KEY = 'order';

    private Client $client;

    private Product $vps;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-12'));

        $this->client = Client::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $this->vps = Product::factory()->create(['name' => 'VPS']);
        $this->vps->prices()->create(['billing_cycle' => self::MONTHLY, 'currency' => 'USD', 'price' => 1000]);
        $this->actingAs(User::factory()->create());
    }

    private function placeOrder(?string $code = null): Order
    {
        $this->actingAs($this->client, 'client');
        $this->post(route('cart.store', $this->vps), ['billing_cycle' => self::MONTHLY])->assertSessionHasNoErrors();
        if ($code) {
            $this->post('/cart/promotion', ['code' => $code])->assertSessionHasNoErrors();
        }
        $this->post(self::CHECKOUT)->assertRedirect();

        return Order::latest('id')->first();
    }

    private function act(string $action, Order $order): TestResponse
    {
        return $this->post(route("admin.orders.{$action}", $order));
    }

    public function test_new_orders_wait_in_the_queue_and_staff_accept_them(): void
    {
        $order = $this->placeOrder();
        $this->assertSame(OrderStatus::Pending, $order->status);

        $this->get(route('admin.dashboard'))->assertSee('Pending orders')->assertSee('Orders (1)');
        $this->get(route(self::INDEX))->assertOk()->assertSee('#'.$order->id)->assertSee('Ada Lovelace')->assertSee('VPS');
        $this->get(route(self::SHOW, $order))->assertOk()->assertSee('Accept')->assertSee(self::FRAUD_BUTTON);

        $this->act('accept', $order)->assertRedirect(route(self::SHOW, $order));

        $order->refresh();
        $this->assertSame(OrderStatus::Active, $order->status);
        $this->assertNotNull($order->reviewed_at);
        $this->get(route(self::INDEX))->assertSee('No orders waiting for review.');
        $this->get(route(self::INDEX, [self::STATUS => 'active']))->assertSee('#'.$order->id);
        $this->act('accept', $order)->assertSessionHasErrors(self::ERROR_KEY);

        // Unpaid, so the service still waits on its invoice.
        $service = Service::sole();
        $this->assertSame(ServiceStatus::Pending, $service->status);
        app(PaymentRecorder::class)->record($order->invoice, 1000, self::MANUAL);
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
    }

    public function test_paid_orders_are_held_until_accepted_when_turned_on(): void
    {
        $this->put(route('admin.settings.update'), ['late_fee_type' => 'fixed', 'hold_new_orders' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue(config('billing.hold_new_orders'));

        $order = $this->placeOrder();
        app(PaymentRecorder::class)->record($order->invoice, 1000, self::MANUAL);

        $service = Service::sole();
        $this->assertSame(ServiceStatus::Pending, $service->status);
        $this->assertSame('2026-11-12', $service->next_due_date->toDateString());

        $this->act('accept', $order)->assertRedirect();
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
    }

    public function test_cancelling_an_unpaid_order_cancels_its_invoice_and_gives_back_the_code_and_stock(): void
    {
        $this->vps->update(['stock_control' => true, 'stock_quantity' => 3]);
        Promotion::create(['code' => 'TEN', 'type' => Promotion::PERCENT, 'value' => 1000]);
        $order = $this->placeOrder('TEN');
        $this->assertSame(2, $this->vps->refresh()->stock_quantity);
        $this->assertSame(1, Promotion::sole()->uses);

        $this->act('cancel', $order)->assertRedirect()->assertSessionHas(self::STATUS, "Order #{$order->id} cancelled.");

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame(InvoiceStatus::Cancelled, $order->invoice->status);
        $this->assertSame(ServiceStatus::Cancelled, Service::sole()->status);
        $this->assertSame(3, $this->vps->refresh()->stock_quantity);
        $this->assertSame(0, Promotion::sole()->uses);
        $this->act('fraud', $order)->assertSessionHasErrors(self::ERROR_KEY);
    }

    public function test_fraud_on_a_paid_order_terminates_its_services_and_leaves_the_invoice_to_refund(): void
    {
        $order = $this->placeOrder();
        app(PaymentRecorder::class)->record($order->invoice, 1000, self::MANUAL);
        $this->assertSame(ServiceStatus::Active, Service::sole()->status);

        $this->act('fraud', $order)->assertSessionHas(self::STATUS, fn ($status) => str_contains($status, 'refund it from the invoice'));

        $this->assertSame(OrderStatus::Fraud, $order->refresh()->status);
        $this->assertSame(InvoiceStatus::Paid, $order->invoice->status);
        $this->assertSame(ServiceStatus::Terminated, Service::sole()->status);
        $this->get(route(self::SHOW, $order))->assertSee('Fraud')->assertDontSee(self::FRAUD_BUTTON);
    }

    public function test_an_order_with_a_part_paid_invoice_is_refunded_first(): void
    {
        $order = $this->placeOrder();
        app(PaymentRecorder::class)->record($order->invoice, 400, self::MANUAL);

        $this->act('cancel', $order)->assertSessionHasErrors(self::ERROR_KEY);
        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
    }

    public function test_only_billing_staff_review_orders(): void
    {
        $order = $this->placeOrder();
        $this->actingAs(User::factory()->create(['role' => StaffRole::Support]), 'web');

        $this->get(route(self::SHOW, $order))->assertOk()->assertDontSee(self::FRAUD_BUTTON);
        $this->act('accept', $order)->assertForbidden();
        $this->act('cancel', $order)->assertForbidden();
    }

    public function test_orders_list_searches_and_filters_by_client(): void
    {
        $first = $this->placeOrder();
        $other = Client::factory()->create(['first_name' => 'Alan', 'last_name' => 'Turing', 'email' => 'alan@example.com']);
        $this->actingAs($other, 'client');
        $this->post(route('cart.store', $this->vps), ['billing_cycle' => self::MONTHLY]);
        $this->post(self::CHECKOUT);
        $second = Order::latest('id')->first();
        $this->actingAs(User::factory()->create());

        $this->get(route(self::INDEX, ['q' => 'alan@example']))->assertSee('Alan Turing')->assertDontSee('Ada Lovelace');
        $this->get(route(self::INDEX, ['q' => '#'.$first->id]))->assertSee('Ada Lovelace')->assertDontSee('Alan Turing');
        $this->get(route(self::INDEX, ['client' => $other->id]))->assertSee('Orders for Alan Turing')->assertSee('#'.$second->id)->assertDontSee('Ada Lovelace');
        $this->get(route('admin.clients.show', $this->client))->assertSee('View all orders')->assertSee('#'.$first->id);
    }

    public function test_checkout_sends_the_order_confirmation_and_the_order_invoice(): void
    {
        $order = $this->placeOrder();

        $templates = EmailLog::orderBy('id')->pluck('template')->all();
        $this->assertEqualsCanonicalizing(['order_invoice', 'order_confirmation'], $templates);

        $confirmation = EmailLog::where('template', 'order_confirmation')->sole();
        $this->assertSame("Order #{$order->id} confirmation", $confirmation->subject);
        $this->assertStringContainsString('- VPS, monthly', $confirmation->body);
        $this->assertStringContainsString('Order total: $10.00', $confirmation->body);
        $this->assertFalse($confirmation->attach_invoice_pdf);

        $invoice = EmailLog::where('template', 'order_invoice')->sole();
        $this->assertSame("Invoice #{$order->invoice->number} for order #{$order->id}", $invoice->subject);
        $this->assertSame($order->invoice_id, $invoice->invoice_id);
    }

    public function test_order_emails_can_be_turned_off(): void
    {
        EmailTemplate::create(['key' => 'order_confirmation', 'subject' => 'x', 'body' => 'x', 'enabled' => false]);

        $this->placeOrder();

        $this->assertSame(['order_invoice'], EmailLog::pluck('template')->all());
    }
}
