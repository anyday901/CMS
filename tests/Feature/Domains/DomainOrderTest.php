<?php

namespace Tests\Feature\Domains;

use App\Billing\PaymentRecorder;
use App\Domains\DomainInfo;
use App\Domains\RegistrarResult;
use App\Enums\DomainStatus;
use App\Enums\OrderStatus;
use App\Models\Client;
use App\Models\Domain;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Ordering\OrderReview;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\DomainSetup;
use Tests\TestCase;

class DomainOrderTest extends TestCase
{
    use DomainSetup, RefreshDatabase;

    private const NAME = 'example.com';

    private const MANUAL = 'manual';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-16'));
        $this->fakeRegistrar();
        $this->tld();
        $this->tld('net', register: 1400);
        $this->client = $this->registrant();
    }

    private function addDomain(array $data = []): void
    {
        $this->post(route('domains.cart'), ['name' => self::NAME, 'type' => Domain::REGISTER, 'years' => 1, ...$data])
            ->assertSessionHasNoErrors()->assertRedirect(route('cart.index'));
    }

    private function checkout(): Invoice
    {
        $this->actingAs($this->client, 'client')->post('/checkout')->assertRedirect();

        return $this->client->invoices()->latest('id')->firstOrFail();
    }

    public function test_the_search_checks_the_name_and_suggests_other_extensions(): void
    {
        $this->registrar->taken['example.net'] = true;

        $this->get(route('domains.index', ['domain' => 'https://www.Example.com/']))
            ->assertOk()
            ->assertSee(self::NAME)
            ->assertSee('example.net')
            ->assertSee('$12.00');

        $this->assertSame([['available', 'example.com,example.net', null]], $this->registrar->calls);
    }

    public function test_a_name_typed_without_an_extension_is_checked_under_each_one(): void
    {
        $this->get(route('domains.index', ['domain' => 'example']))->assertOk()->assertSee(self::NAME)->assertSee('example.net');
    }

    public function test_extensions_not_sold_and_bad_names_are_explained(): void
    {
        $this->get(route('domains.index', ['domain' => 'example.xyz']))->assertSee("We don't sell that extension. Try one of: .com, .net.");
        $this->get(route('domains.index', ['domain' => 'bad_name!.com']))->assertSee("That isn't a domain name.");
    }

    public function test_a_registration_is_ordered_paid_for_and_registered(): void
    {
        $this->actingAs($this->client, 'client');
        $this->addDomain(['id_protection' => true]);
        $this->get(route('cart.index'))->assertSee(self::NAME)->assertSee('$15.00');

        $invoice = $this->checkout();

        $this->assertSame(1500, $invoice->total);
        $item = $invoice->items()->sole();
        $this->assertSame(InvoiceItem::TYPE_DOMAIN_REGISTER, $item->type);
        $this->assertSame('Domain registration: example.com, 1 year (10/16/2026 - 10/15/2027), with ID protection', $item->description);
        $domain = Domain::sole();
        $this->assertSame(DomainStatus::Pending, $domain->status);
        $this->assertSame(1800, $domain->recurring_amount);
        $this->assertSame(['ns1.host.test', 'ns2.host.test'], $domain->nameservers);
        $this->assertSame('fake', $domain->registrar);
        $this->assertSame(Order::sole()->id, $domain->order_id);
        $this->assertSame(['available'], $this->registrar->methods());

        app(PaymentRecorder::class)->record($invoice, 1500, self::MANUAL);

        $domain->refresh();
        $this->assertSame(['register', self::NAME, 1], $this->registrar->calls[1]);
        $this->assertSame(DomainStatus::Active, $domain->status);
        $this->assertSame('2027-10-16', $domain->expiry_date->toDateString());
        $this->assertSame('2027-10-16', $domain->next_due_date->toDateString());
        $this->assertSame('done', $domain->registrar_status);
        $this->assertSame('R1', $domain->registrarValue('order_id'));
        $this->assertTrue(EmailLog::where('template', 'domain_registered')->where('client_id', $this->client->id)->exists());
    }

    public function test_a_taken_name_cant_be_registered_and_a_free_one_cant_be_transferred(): void
    {
        $this->registrar->taken[self::NAME] = true;

        $this->post(route('domains.cart'), ['name' => self::NAME, 'type' => Domain::REGISTER, 'years' => 1])
            ->assertSessionHasErrors(['name' => 'example.com is already taken. If it is yours, transfer it to us instead.']);
        $this->post(route('domains.cart'), ['name' => 'free.com', 'type' => Domain::TRANSFER, 'years' => 1, 'epp_code' => 'X'])
            ->assertSessionHasErrors(['name' => "free.com isn't registered yet, so it can't be transferred. Register it instead."]);
    }

    public function test_terms_and_transfers_need_a_price_and_an_epp_code(): void
    {
        $this->registrar->taken[self::NAME] = true;

        $this->post(route('domains.cart'), ['name' => self::NAME, 'type' => Domain::REGISTER, 'years' => 3])
            ->assertSessionHasErrors(['name' => ".com domains aren't sold for 3 year(s) in USD."]);
        $this->post(route('domains.cart'), ['name' => self::NAME, 'type' => Domain::TRANSFER, 'years' => 2, 'epp_code' => 'X'])
            ->assertSessionHasErrors(['name' => "Transfers of .com domains for 2 year(s) aren't sold in USD."]);
        $this->post(route('domains.cart'), ['name' => self::NAME, 'type' => Domain::TRANSFER, 'years' => 1])
            ->assertSessionHasErrors(['name' => 'Enter the EPP code for example.com. Its current registrar gives it to you.']);
    }

    public function test_a_name_already_with_us_cant_be_ordered_again(): void
    {
        Domain::factory()->create(['name' => self::NAME]);

        $this->post(route('domains.cart'), ['name' => self::NAME, 'type' => Domain::REGISTER, 'years' => 1])
            ->assertSessionHasErrors(['name' => 'example.com is already registered with us.']);
    }

    public function test_a_transfer_waits_for_the_registrar_and_the_sync_finishes_it(): void
    {
        $this->registrar->taken[self::NAME] = true;
        $this->actingAs($this->client, 'client');
        $this->addDomain(['type' => Domain::TRANSFER, 'epp_code' => ' AUTH-1 ']);
        $invoice = $this->checkout();
        $this->assertSame(900, $invoice->total);
        $this->assertSame(InvoiceItem::TYPE_DOMAIN_TRANSFER, $invoice->items()->sole()->type);

        app(PaymentRecorder::class)->record($invoice, 900, self::MANUAL);

        $domain = Domain::sole();
        $this->assertSame(['transfer', self::NAME, 'AUTH-1'], $this->registrar->calls[1]);
        $this->assertSame(DomainStatus::PendingTransfer, $domain->status);
        $this->assertNull($domain->expiry_date);

        $this->registrar->info = new DomainInfo(DomainInfo::PENDING_TRANSFER);
        $this->artisan('domains:sync')->assertSuccessful();
        $this->assertSame(DomainStatus::PendingTransfer, $domain->refresh()->status);

        $this->registrar->info = new DomainInfo(DomainInfo::ACTIVE, CarbonImmutable::parse('2028-03-01'), ['ns1.other.test', 'ns2.other.test'], true);
        $this->artisan('domains:sync')->assertSuccessful();

        $domain->refresh();
        $this->assertSame(DomainStatus::Active, $domain->status);
        $this->assertSame('2028-03-01', $domain->next_due_date->toDateString());
        $this->assertSame(['ns1.other.test', 'ns2.other.test'], $domain->nameservers);
        $this->assertTrue($domain->locked);
        $this->assertTrue(EmailLog::where('template', 'domain_transferred')->exists());
    }

    public function test_a_failed_registration_is_kept_for_staff_to_retry(): void
    {
        $this->registrar->result = RegistrarResult::failed('Name is reserved');
        $this->actingAs($this->client, 'client');
        $this->addDomain();
        $invoice = $this->checkout();

        app(PaymentRecorder::class)->record($invoice, $invoice->total, self::MANUAL);

        $domain = Domain::sole();
        $this->assertSame(DomainStatus::Pending, $domain->status);
        $this->assertSame('failed', $domain->registrar_status);
        $this->assertSame('Name is reserved', $domain->registrar_error);
    }

    public function test_a_registrant_without_an_address_isnt_sent_to_the_registrar(): void
    {
        $this->client->update(['address1' => null]);
        $this->actingAs($this->client, 'client');
        $this->addDomain();
        $invoice = $this->checkout();

        app(PaymentRecorder::class)->record($invoice, $invoice->total, self::MANUAL);

        $this->assertNotContains('register', $this->registrar->methods());
        $this->assertStringContainsString('missing', (string) Domain::sole()->registrar_error);
    }

    public function test_held_orders_register_once_staff_accept_them_and_cancelled_ones_never_do(): void
    {
        config(['billing.hold_new_orders' => true]);
        $this->actingAs($this->client, 'client');
        $this->addDomain();
        $invoice = $this->checkout();
        app(PaymentRecorder::class)->record($invoice, $invoice->total, self::MANUAL);
        $this->assertNotContains('register', $this->registrar->methods());

        $order = Order::sole();
        $this->assertSame(OrderStatus::Pending, $order->status);
        app(OrderReview::class)->accept($order, User::factory()->create());

        $this->assertContains('register', $this->registrar->methods());
        $this->assertSame(DomainStatus::Active, Domain::sole()->status);
    }

    public function test_cancelling_a_held_order_cancels_its_domains(): void
    {
        config(['billing.hold_new_orders' => true]);
        $this->actingAs($this->client, 'client');
        $this->addDomain();
        $this->checkout();

        app(OrderReview::class)->cancel(Order::sole(), User::factory()->create());

        $this->assertSame(DomainStatus::Cancelled, Domain::sole()->status);
        $this->assertNotContains('register', $this->registrar->methods());
    }

    public function test_hosting_orders_can_register_their_domain_too(): void
    {
        $product = Product::factory()->create(['name' => 'Web Hosting', 'module' => 'cpanel']);
        $product->prices()->create(['billing_cycle' => 'monthly', 'currency' => 'USD', 'price' => 500]);
        $this->actingAs($this->client, 'client');

        $this->post(route('cart.store', $product), ['billing_cycle' => 'monthly', 'label' => 'MySite.com', 'register_domain' => '1'])->assertSessionHasNoErrors();
        $invoice = $this->checkout();

        $this->assertSame(500 + 1200, $invoice->total);
        $this->assertSame('mysite.com', Domain::sole()->name);
    }
}
