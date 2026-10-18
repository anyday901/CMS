<?php

namespace Tests\Feature\Store;

use App\Models\Client;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\Service;
use App\Ordering\Checkout;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StoreCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const DOLLAR_PLAN = 'Dollar plan';

    private const CURRENCY_PICKER = 'id="store-currency"';

    private const STORE_URL = '/store';

    private const MONTHLY = 'monthly';

    private const PASSWORD = 'a-long-password';

    private const PICK = '/store/currency';

    private Product $vps;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-11'));
        Currency::create(['code' => 'EUR', 'rate' => '0.9']);

        $this->vps = Product::factory()->create(['name' => 'VPS']);
        $this->vps->prices()->create(['billing_cycle' => self::MONTHLY, 'currency' => 'USD', 'price' => 1000]);
        $this->vps->prices()->create(['billing_cycle' => self::MONTHLY, 'currency' => 'EUR', 'price' => 900, 'setup_fee' => 100]);
        Product::factory()->create(['name' => self::DOLLAR_PLAN])
            ->prices()->create(['billing_cycle' => self::MONTHLY, 'currency' => 'USD', 'price' => 500]);
    }

    public function test_guests_pick_a_currency_for_the_store(): void
    {
        $this->get(self::STORE_URL)->assertOk()->assertSee(self::CURRENCY_PICKER, false)
            ->assertSee('$10.00')->assertSee(self::DOLLAR_PLAN);

        $this->from(self::STORE_URL)->post(self::PICK, ['currency' => 'EUR'])->assertRedirect(self::STORE_URL);
        $this->assertSame('EUR', session(Checkout::SESSION_CURRENCY));

        // Only products priced in euros, at their euro prices.
        $this->get(self::STORE_URL)->assertOk()->assertSee('€9.00')->assertDontSee(self::DOLLAR_PLAN);

        $this->post(self::PICK, ['currency' => 'GBP'])->assertSessionHasErrors('currency');
        $this->assertSame('EUR', session(Checkout::SESSION_CURRENCY));
    }

    public function test_a_currency_removed_since_falls_back_to_the_default(): void
    {
        $this->withSession([Checkout::SESSION_CURRENCY => 'GBP'])->get(self::STORE_URL)->assertOk()->assertSee(self::DOLLAR_PLAN);
    }

    public function test_the_picker_is_hidden_with_one_currency(): void
    {
        Currency::where('code', 'EUR')->delete();

        $this->get(self::STORE_URL)->assertOk()->assertDontSee(self::CURRENCY_PICKER, false);
    }

    public function test_a_guest_signs_up_in_the_currency_they_picked(): void
    {
        $this->post(self::PICK, ['currency' => 'EUR']);
        $this->post(route('cart.store', $this->vps), ['billing_cycle' => self::MONTHLY])->assertRedirect(route('cart.index'));
        $this->get('/cart')->assertOk()->assertSee('€10.00'); // 9.00 + 1.00 setup

        $this->post('/checkout', [
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
            'address1' => '1 Rue', 'city' => 'Paris', 'postcode' => '75001', 'country' => 'fr',
        ])->assertRedirect();

        $client = Client::where('email', 'ada@example.com')->sole();
        $this->assertSame('EUR', $client->currency);
        $invoice = Order::sole()->invoice;
        $this->assertSame('EUR', $invoice->currency);
        $this->assertSame(1000, $invoice->total);
        $this->assertSame(900, Service::sole()->recurring_amount);
    }

    public function test_signed_in_clients_keep_their_account_currency(): void
    {
        $client = Client::factory()->create(['currency' => 'USD']);
        $this->actingAs($client, 'client');

        $this->post(self::PICK, ['currency' => 'EUR']);
        $this->assertNull(session(Checkout::SESSION_CURRENCY));
        $this->get(self::STORE_URL)->assertOk()->assertSee('$10.00')->assertSee(self::DOLLAR_PLAN)->assertDontSee(self::CURRENCY_PICKER, false);
        $this->assertSame('USD', Checkout::currencyFor($client));
    }

    public function test_configurable_options_are_priced_in_the_clients_currency(): void
    {
        $disk = $this->vps->options()->create(['name' => 'Disk', 'type' => ProductOption::DROPDOWN]);
        $disk->choices()->create(['name' => '25 GB']);
        $big = $disk->choices()->create(['name' => '50 GB', 'prices' => [self::MONTHLY => 300], 'sort_order' => 1]);
        $this->post(self::PICK, ['currency' => 'EUR']);

        // Not priced in euros yet, so it can't be ordered in them.
        $this->post(route('cart.store', $this->vps), ['billing_cycle' => self::MONTHLY, 'options' => [$disk->id => $big->id]])
            ->assertSessionHasErrors();

        $big->update(['currency_prices' => ['EUR' => ['prices' => [self::MONTHLY => 250], 'setup_fee' => 50]]]);
        $this->get(route('store.product', $this->vps))->assertSee('50 GB (+€2.50)');
        $this->post(route('cart.store', $this->vps), ['billing_cycle' => self::MONTHLY, 'options' => [$disk->id => $big->id]])
            ->assertRedirect(route('cart.index'));
        $this->get('/cart')->assertOk()->assertSee('€13.00'); // 9.00 + 2.50 + 1.00 setup + 0.50 option setup
    }
}
