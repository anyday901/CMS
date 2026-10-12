<?php

namespace Tests\Feature\Store;

use App\Billing\InvoiceGenerator;
use App\Billing\PaymentRecorder;
use App\Billing\ServiceLifecycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductOption;
use App\Models\Promotion;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use RefreshDatabase;

    private const MONTHLY = 'monthly';

    private const PASSWORD = 'a-long-password';

    private ProductGroup $group;

    private Product $vps;

    private Product $backups;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-11'));

        $this->group = ProductGroup::create(['name' => 'Servers', 'slug' => 'servers']);
        $this->vps = Product::factory()->create(['name' => 'VPS', 'product_group_id' => $this->group->id, 'description' => 'A small server']);
        $this->vps->prices()->create(['billing_cycle' => self::MONTHLY, 'currency' => 'USD', 'price' => 1000, 'setup_fee' => 500]);
        $this->vps->prices()->create(['billing_cycle' => 'annually', 'currency' => 'USD', 'price' => 10000]);

        $this->backups = Product::factory()->create(['name' => 'Backups', 'is_addon' => true]);
        $this->backups->prices()->create(['billing_cycle' => self::MONTHLY, 'currency' => 'USD', 'price' => 200]);
        $this->vps->addons()->attach($this->backups);
    }

    private function signUpDetails(array $overrides = []): array
    {
        return [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'address1' => '1 Main St',
            'city' => 'Austin',
            'state' => 'TX',
            'postcode' => '78701',
            'country' => 'us',
            ...$overrides,
        ];
    }

    public function test_catalogue_lists_orderable_products_by_group(): void
    {
        $hidden = ProductGroup::create(['name' => 'Secret', 'slug' => 'secret', 'visible' => false]);
        Product::factory()->create(['name' => 'Hidden plan', 'product_group_id' => $hidden->id])
            ->prices()->create(['billing_cycle' => self::MONTHLY, 'currency' => 'USD', 'price' => 100]);
        Product::factory()->create(['name' => 'Retired plan', 'active' => false])
            ->prices()->create(['billing_cycle' => self::MONTHLY, 'currency' => 'USD', 'price' => 100]);
        Product::factory()->create(['name' => 'Unpriced plan']);
        Product::factory()->create(['name' => 'Loose plan'])
            ->prices()->create(['billing_cycle' => self::MONTHLY, 'currency' => 'USD', 'price' => 300]);

        $this->get('/store')->assertOk()
            ->assertSeeInOrder(['Servers', 'VPS', 'From', '$10.00', 'More products', 'Loose plan'])
            ->assertDontSee('Hidden plan')->assertDontSee('Retired plan')->assertDontSee('Unpriced plan')
            ->assertDontSee('Backups');
        $this->get('/store/servers')->assertOk()->assertSee('VPS');
        $this->get('/store/secret')->assertNotFound();
        $this->get(route('store.product', $this->vps))->assertOk()->assertSee('Backups')->assertSee('Annually');
        $this->get(route('store.product', $this->backups))->assertNotFound();
    }

    public function test_guest_orders_with_an_add_on_and_signs_up_at_checkout(): void
    {
        $disk = $this->vps->options()->create(['name' => 'Disk', 'type' => ProductOption::DROPDOWN]);
        $disk->choices()->create(['name' => '25 GB']);
        $big = $disk->choices()->create(['name' => '50 GB', 'prices' => [self::MONTHLY => 300], 'sort_order' => 1]);

        $this->post(route('cart.store', $this->vps), [
            'billing_cycle' => self::MONTHLY,
            'options' => [$disk->id => $big->id],
            'label' => 'web1.example.com',
            'addons' => [$this->backups->id],
        ])->assertRedirect(route('cart.index'));

        $this->get('/cart')->assertOk()
            ->assertSee('VPS')->assertSee('web1.example.com')->assertSee('Disk: 50 GB')->assertSee('Backups')
            ->assertSee('$20.00'); // 13.00 + 5.00 setup + 2.00 backups

        $this->get('/checkout')->assertOk()->assertSee('Create your account');
        $response = $this->post('/checkout', $this->signUpDetails());

        $client = Client::where('email', 'ada@example.com')->sole();
        $this->assertAuthenticatedAs($client, 'client');
        $this->assertSame('US', $client->country);
        $order = Order::sole();
        $invoice = $order->invoice;
        $response->assertRedirect(route('portal.invoices.show', $invoice));

        $vps = Service::where('product_id', $this->vps->id)->sole();
        $addon = Service::where('product_id', $this->backups->id)->sole();
        $this->assertSame($vps->id, $addon->parent_service_id);
        $this->assertSame([$order->id, $order->id], [$vps->order_id, $addon->order_id]);
        $this->assertSame(1300, $vps->recurring_amount);
        $this->assertSame('web1.example.com', $vps->label);
        $this->assertSame(ServiceStatus::Pending, $vps->status);
        $this->assertSame(2000, $invoice->total);
        $this->assertSame([InvoiceItem::TYPE_SERVICE, InvoiceItem::TYPE_SETUP_FEE, InvoiceItem::TYPE_SERVICE], $invoice->items()->orderBy('id')->pluck('type')->all());

        // The cart is empty afterwards.
        $this->get('/cart')->assertSee('Your cart is empty');

        app(PaymentRecorder::class)->record($invoice, 2000, 'manual');
        $this->assertSame(ServiceStatus::Active, $vps->refresh()->status);
        $this->assertSame(ServiceStatus::Active, $addon->refresh()->status);
    }

    public function test_sign_up_needs_a_new_email_and_a_good_password(): void
    {
        Client::factory()->create(['email' => 'ada@example.com']);
        $this->post(route('cart.store', $this->vps), ['billing_cycle' => self::MONTHLY]);

        $this->post('/checkout', $this->signUpDetails())->assertSessionHasErrors('email');
        $this->post('/checkout', $this->signUpDetails(['email' => 'new@example.com', 'password_confirmation' => 'different']))->assertSessionHasErrors('password');
        $this->assertSame(0, Order::count());
        $this->assertGuest('client');
    }

    public function test_logged_in_client_checks_out_without_signing_up(): void
    {
        $client = Client::factory()->create();
        $this->actingAs($client, 'client');

        $this->post(route('cart.store', $this->vps), ['billing_cycle' => 'annually'])->assertRedirect(route('cart.index'));
        $this->get('/checkout')->assertOk()->assertSee('Ordering as');
        $this->post('/checkout')->assertRedirect();

        $service = $client->services()->sole();
        $this->assertSame(10000, $service->recurring_amount);
        $this->assertSame('annually', $service->billing_cycle->value);
        $this->assertSame(10000, $client->invoices()->sole()->total);
    }

    public function test_products_that_cannot_be_ordered_are_refused(): void
    {
        $this->post(route('cart.store', $this->vps), ['billing_cycle' => 'quarterly'])->assertSessionHasErrors('billing_cycle');
        $this->post(route('cart.store', $this->backups), ['billing_cycle' => self::MONTHLY])->assertSessionHasErrors('billing_cycle');
        $other = Product::factory()->create(['is_addon' => true]);
        $other->prices()->create(['billing_cycle' => self::MONTHLY, 'currency' => 'USD', 'price' => 100]);
        $this->post(route('cart.store', $this->vps), ['billing_cycle' => self::MONTHLY, 'addons' => [$other->id]])->assertSessionHasErrors('billing_cycle');
        $this->get('/cart')->assertSee('Your cart is empty');
    }

    public function test_a_product_withdrawn_after_it_was_added_blocks_checkout_until_removed(): void
    {
        $this->post(route('cart.store', $this->vps), ['billing_cycle' => self::MONTHLY]);
        $this->vps->update(['active' => false]);

        $this->get('/cart')->assertSee('no longer available');
        $this->get('/checkout')->assertRedirect(route('cart.index'));
        $this->post('/checkout', $this->signUpDetails())->assertRedirect(route('cart.index'));
        $this->assertSame(0, Client::count());
    }

    public function test_percent_code_on_the_first_payment_only(): void
    {
        Promotion::create(['code' => 'HALF', 'type' => Promotion::PERCENT, 'value' => 5000]);
        $this->post(route('cart.store', $this->vps), ['billing_cycle' => self::MONTHLY, 'addons' => [$this->backups->id]]);

        $this->post('/cart/promotion', ['code' => ' half '])->assertSessionHasNoErrors();
        $this->get('/cart')->assertSee('HALF')->assertSee('$11.00'); // (10.00 + 2.00) / 2 + 5.00 setup
        $this->post('/checkout', $this->signUpDetails());

        $invoice = Invoice::sole();
        $this->assertSame(1100, $invoice->total);
        $this->assertSame([-500, -100], $invoice->items()->where('type', InvoiceItem::TYPE_PROMOTION)->orderBy('id')->pluck('amount')->all());
        $this->assertSame(1, Promotion::sole()->uses);
        $this->assertSame('HALF', Order::sole()->promotion_code);

        // Renewals are full price.
        $vps = Service::where('product_id', $this->vps->id)->sole();
        $this->assertSame(0, $vps->recurring_discount);
        app(PaymentRecorder::class)->record($invoice, 1100, 'manual');
        $renewal = app(InvoiceGenerator::class)->generate(CarbonImmutable::parse('2026-11-11'))->sole();
        $this->assertSame(1200, $renewal->total);
    }

    public function test_recurring_fixed_code_comes_off_every_renewal_of_covered_products(): void
    {
        Promotion::create(['code' => 'FIVE', 'type' => Promotion::FIXED, 'value' => 500, 'recurring' => true, 'product_ids' => [$this->vps->id]]);
        $this->post(route('cart.store', $this->vps), ['billing_cycle' => self::MONTHLY, 'addons' => [$this->backups->id]]);
        $this->post('/cart/promotion', ['code' => 'FIVE']);
        $this->post('/checkout', $this->signUpDetails());

        $invoice = Invoice::sole();
        $this->assertSame(1200, $invoice->total); // 10.00 - 5.00 + 5.00 setup + 2.00 backups
        $vps = Service::where('product_id', $this->vps->id)->sole();
        $this->assertSame(500, $vps->recurring_discount);
        $this->assertSame(500, $vps->netRecurring());

        app(PaymentRecorder::class)->record($invoice, 1200, 'manual');
        $renewal = app(InvoiceGenerator::class)->generate(CarbonImmutable::parse('2026-11-11'))->sole();
        $this->assertSame(700, $renewal->total);
        $this->assertSame('Promotion code FIVE: VPS', $renewal->items()->where('type', InvoiceItem::TYPE_PROMOTION)->sole()->description);
    }

    public function test_free_order_is_paid_and_activated_at_once(): void
    {
        $this->vps->prices()->where('billing_cycle', self::MONTHLY)->update(['setup_fee' => 0]);
        Promotion::create(['code' => 'FREE', 'type' => Promotion::PERCENT, 'value' => 10000, 'product_ids' => [$this->vps->id]]);
        $this->post(route('cart.store', $this->vps), ['billing_cycle' => self::MONTHLY]);
        $this->post('/cart/promotion', ['code' => 'FREE']);

        $this->post('/checkout', $this->signUpDetails())->assertRedirect(route('portal.services.index'));

        $this->assertSame(InvoiceStatus::Paid, Invoice::sole()->status);
        $service = Service::sole();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame('2026-11-11', $service->next_due_date->toDateString());
    }

    public function test_codes_that_cannot_be_used_are_refused(): void
    {
        $client = Client::factory()->create();
        $other = Product::factory()->create();
        Promotion::create(['code' => 'OLD', 'type' => Promotion::PERCENT, 'value' => 1000, 'expires_on' => '2026-10-10']);
        Promotion::create(['code' => 'SOON', 'type' => Promotion::PERCENT, 'value' => 1000, 'starts_on' => '2026-10-12']);
        Promotion::create(['code' => 'OFF', 'type' => Promotion::PERCENT, 'value' => 1000, 'active' => false]);
        Promotion::create(['code' => 'GONE', 'type' => Promotion::PERCENT, 'value' => 1000, 'max_uses' => 1])->forceFill(['uses' => 1])->save();
        Promotion::create(['code' => 'OTHER', 'type' => Promotion::PERCENT, 'value' => 1000, 'product_ids' => [$other->id]]);
        $once = Promotion::create(['code' => 'ONCE', 'type' => Promotion::PERCENT, 'value' => 1000, 'once_per_client' => true]);
        Order::create(['client_id' => $client->id, 'promotion_id' => $once->id]);

        $this->actingAs($client, 'client');
        $this->post(route('cart.store', $this->vps), ['billing_cycle' => self::MONTHLY]);

        foreach (['NOPE' => "isn't valid", 'OLD' => 'expired', 'SOON' => "isn't valid", 'OFF' => "isn't valid", 'GONE' => 'used up', 'OTHER' => "doesn't apply", 'ONCE' => 'already used'] as $code => $message) {
            $this->post('/cart/promotion', ['code' => $code])->assertSessionHasErrors('code');
            $this->assertStringContainsString($message, session('errors')->first('code'));
        }
        $this->get('/cart')->assertDontSee('applied');
    }

    public function test_fixed_codes_need_the_billing_currency(): void
    {
        $client = Client::factory()->create(['currency' => 'EUR']);
        $this->vps->prices()->create(['billing_cycle' => self::MONTHLY, 'currency' => 'EUR', 'price' => 900]);
        Promotion::create(['code' => 'FIVE', 'type' => Promotion::FIXED, 'value' => 500]);
        $this->actingAs($client, 'client');
        $this->post(route('cart.store', $this->vps), ['billing_cycle' => self::MONTHLY]);

        $this->post('/cart/promotion', ['code' => 'FIVE'])->assertSessionHasErrors('code');
    }

    public function test_code_used_up_after_it_was_applied_stops_checkout(): void
    {
        $promotion = Promotion::create(['code' => 'LAST', 'type' => Promotion::PERCENT, 'value' => 1000, 'max_uses' => 1]);
        $this->post(route('cart.store', $this->vps), ['billing_cycle' => self::MONTHLY]);
        $this->post('/cart/promotion', ['code' => 'LAST'])->assertSessionHasNoErrors();
        $promotion->forceFill(['uses' => 1])->save();

        $this->post('/checkout', $this->signUpDetails())->assertRedirect(route('cart.index'))->assertSessionHasErrors('cart');
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Client::count());
    }

    public function test_client_adds_an_add_on_to_a_service_they_have(): void
    {
        $client = Client::factory()->create();
        $service = Service::factory()->create(['client_id' => $client->id, 'product_id' => $this->vps->id]);
        $this->actingAs($client, 'client');

        $this->get(route('portal.services.show', $service))->assertSee('Backups')->assertSee('Add to cart');
        $this->post(route('cart.addon'), ['service_id' => $service->id, 'product_id' => $this->backups->id])->assertRedirect(route('cart.index'));
        $this->get('/cart')->assertSee('For VPS');
        $this->post('/checkout')->assertRedirect();

        $addon = $service->addons()->sole();
        $this->assertSame($this->backups->id, $addon->product_id);
        $this->assertSame(200, $client->invoices()->sole()->total);
        $this->get(route('portal.services.show', $service))->assertDontSee('Add to cart');

        // Someone else's service can't get one.
        $theirs = Service::factory()->create(['product_id' => $this->vps->id]);
        $this->post(route('cart.addon'), ['service_id' => $theirs->id, 'product_id' => $this->backups->id])->assertSessionHasErrors('product_id');
    }

    public function test_guests_log_in_to_order_add_ons_and_can_log_in_from_checkout(): void
    {
        $this->post(route('cart.addon'), ['service_id' => 1, 'product_id' => $this->backups->id])->assertRedirect(route('portal.login'));

        $client = Client::factory()->create(['password' => self::PASSWORD]);
        $this->post(route('cart.store', $this->vps), ['billing_cycle' => self::MONTHLY]);
        $this->get(route('checkout.login'))->assertRedirect(route('portal.login'));
        $this->post(route('portal.login'), ['email' => $client->email, 'password' => self::PASSWORD])->assertRedirect(route('checkout.show'));
        $this->get('/checkout')->assertOk()->assertSee('Ordering as');
    }

    public function test_add_ons_follow_their_service_through_suspension_and_termination(): void
    {
        $lifecycle = app(ServiceLifecycle::class);
        $service = Service::factory()->create(['product_id' => $this->vps->id]);
        $addon = Service::factory()->create(['client_id' => $service->client_id, 'product_id' => $this->backups->id, 'parent_service_id' => $service->id]);
        $alone = Service::factory()->create(['client_id' => $service->client_id, 'product_id' => $this->backups->id, 'parent_service_id' => $service->id]);
        $lifecycle->suspend($alone, 'manual');

        $lifecycle->suspend($service, 'overdue');
        $this->assertSame('overdue', $addon->refresh()->suspension_reason);
        $lifecycle->unsuspend($service->refresh());
        $this->assertSame(ServiceStatus::Active, $addon->refresh()->status);
        $this->assertSame(ServiceStatus::Suspended, $alone->refresh()->status);

        $lifecycle->terminate($service->refresh());
        $this->assertSame(ServiceStatus::Terminated, $addon->refresh()->status);
        $this->assertSame(ServiceStatus::Terminated, $alone->refresh()->status);
    }
}
