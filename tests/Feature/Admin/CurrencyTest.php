<?php

namespace Tests\Feature\Admin;

use App\Billing\ExchangeRates;
use App\Enums\BillingCycle;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Tld;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const INDEX = '/admin/settings/currencies';

    private const MONTHLY = 'monthly';

    /** The ECB's feed, trimmed: units per euro. */
    private const FEED = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
            <gesmes:subject>Reference rates</gesmes:subject>
            <Cube>
                <Cube time="2026-10-16">
                    <Cube currency="USD" rate="1.0825"/>
                    <Cube currency="GBP" rate="0.8412"/>
                    <Cube currency="JPY" rate="162.35"/>
                </Cube>
            </Cube>
        </gesmes:Envelope>
        XML;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function fakeFeed(string $body = self::FEED, int $status = 200): void
    {
        Http::fake([ExchangeRates::FEED => Http::response($body, $status)]);
    }

    public function test_install_seeds_the_billing_currency_as_default(): void
    {
        $this->assertSame(['USD'], Currency::codes());
        $this->assertTrue(Currency::where('code', 'USD')->value('is_default'));
        $this->get(self::INDEX)->assertOk()->assertSee('USD')->assertSee('Default');
    }

    public function test_staff_add_a_currency_with_a_rate(): void
    {
        $this->post(self::INDEX, ['code' => 'eur', 'rate' => '0,9238'])->assertRedirect(self::INDEX);
        $this->assertSame('0.9238', Currency::where('code', 'EUR')->value('rate'));

        $this->post(self::INDEX, ['code' => 'EUR'])->assertSessionHasErrors('code');
        $this->post(self::INDEX, ['code' => 'EURO'])->assertSessionHasErrors('code');
        $this->post(self::INDEX, ['code' => 'GBP', 'rate' => '-1'])->assertSessionHasErrors('rate');
        $this->post(self::INDEX, ['code' => 'CAD', 'rate' => '0'])->assertSessionHasErrors('rate');
        $this->assertSame(['USD', 'EUR'], Currency::codes());

        $eur = Currency::where('code', 'EUR')->first();
        $this->put(self::INDEX."/{$eur->id}", ['rate' => '0.95'])->assertRedirect(self::INDEX);
        $this->assertSame('0.95', $eur->fresh()->rate);
    }

    public function test_rates_update_from_the_ecb_crossed_to_the_default_currency(): void
    {
        Currency::create(['code' => 'EUR', 'rate' => '1']);
        Currency::create(['code' => 'GBP', 'rate' => '1']);
        Currency::create(['code' => 'JPY', 'rate' => '1']);
        Currency::create(['code' => 'XAF', 'rate' => '600']);
        $this->fakeFeed();

        $this->post(self::INDEX.'/rates')->assertRedirect(self::INDEX)
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'Updated 3 exchange rates') && str_contains($status, '2026-10-16') && str_contains($status, 'no rate for XAF'));

        // 1 / 1.0825, 0.8412 / 1.0825 and 162.35 / 1.0825, to 10 places, half up.
        $this->assertSame('0.9237875289', Currency::where('code', 'EUR')->value('rate'));
        $this->assertSame('0.7770900693', Currency::where('code', 'GBP')->value('rate'));
        $this->assertSame('149.9769053118', Currency::where('code', 'JPY')->value('rate'));
        // Not quoted by the ECB, so unchanged.
        $this->assertSame('600', Currency::where('code', 'XAF')->value('rate'));
        $this->assertNull(Currency::where('code', 'XAF')->value('rate_updated_at'));
        $this->assertSame('1', Currency::where('code', 'USD')->value('rate'));
        Http::assertSentCount(1);
    }

    public function test_rates_are_left_alone_when_the_feed_fails_or_lacks_the_default(): void
    {
        Currency::create(['code' => 'EUR', 'rate' => '0.9']);

        // Retried once, then given up on; then a feed without the default currency.
        Http::fake([ExchangeRates::FEED => Http::sequence()->push('nope', 500)->push('nope', 500)
            ->push(str_replace('<Cube currency="USD" rate="1.0825"/>', '', self::FEED))]);
        $this->post(self::INDEX.'/rates')->assertSessionHasErrors(['rates' => 'The European Central Bank answered with HTTP 500.']);

        $this->post(self::INDEX.'/rates')->assertSessionHasErrors(['rates' => "The European Central Bank doesn't publish a rate for USD, the default currency, so no rates were changed."]);
        $this->assertSame('0.9', Currency::where('code', 'EUR')->value('rate'));
    }

    public function test_a_euro_default_takes_the_ecb_rates_as_they_are(): void
    {
        config(['billing.currency' => 'EUR']);
        Currency::query()->update(['is_default' => false]);
        Currency::create(['code' => 'EUR', 'is_default' => true]);
        $this->fakeFeed();

        $this->artisan('currencies:update-rates')->assertSuccessful();

        $this->assertSame('1.0825', Currency::where('code', 'USD')->value('rate'));
    }

    public function test_the_scheduled_update_only_runs_when_turned_on(): void
    {
        Currency::create(['code' => 'EUR', 'rate' => '0.9']);
        $this->fakeFeed();

        $this->artisan('currencies:update-rates --scheduled')->expectsOutputToContain('are off')->assertSuccessful();
        $this->assertSame('0.9', Currency::where('code', 'EUR')->value('rate'));
        Http::assertNothingSent();

        $this->put('/admin/settings/automation', [
            'run_time' => '00:05', 'invoice_days_before_due' => 7, 'late_fee_type' => 'fixed', 'overage_due_days' => 7, 'update_exchange_rates' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(config('billing.update_exchange_rates'));

        $this->artisan('currencies:update-rates --scheduled')->assertSuccessful();
        $this->assertSame('0.9237875289', Currency::where('code', 'EUR')->value('rate'));
        $this->get('/admin/settings/cron')->assertOk()->assertSee('currencies:update-rates');
    }

    public function test_amounts_convert_with_half_up_rounding_to_the_cent(): void
    {
        Currency::create(['code' => 'EUR', 'rate' => '0.9235']);
        Currency::create(['code' => 'JPY', 'rate' => '150.5']);

        $this->assertSame(924, Currency::convert(1000, 'USD', 'EUR'));    // 923.5, half up
        $this->assertSame(1083, Currency::convert(1000, 'EUR', 'USD'));   // 1082.83...
        $this->assertSame(150500, Currency::convert(1000, 'USD', 'JPY'));
        $this->assertSame(614, Currency::convert(100000, 'JPY', 'EUR'));   // 100000 * 0.9235 / 150.5 = 613.62...
        $this->assertSame(0, Currency::convert(0, 'USD', 'EUR'));
        $this->assertSame(-1847, Currency::convert(-2000, 'USD', 'EUR'));
    }

    public function test_staff_update_prices_from_the_default_currency(): void
    {
        Currency::create(['code' => 'EUR', 'rate' => '0.9235']);
        $product = Product::factory()->create();
        $product->prices()->create(['billing_cycle' => self::MONTHLY, 'currency' => 'USD', 'price' => 1999, 'setup_fee' => 500]);
        $product->prices()->create(['billing_cycle' => 'annually', 'currency' => 'EUR', 'price' => 1]);
        $only = Product::factory()->create();
        $only->prices()->create(['billing_cycle' => self::MONTHLY, 'currency' => 'EUR', 'price' => 700]);
        $option = $product->options()->create(['name' => 'Disk', 'type' => 'dropdown']);
        $choice = $option->choices()->create(['name' => '50 GB', 'prices' => [self::MONTHLY => 300], 'setup_fee' => 150]);
        $tld = Tld::create(['extension' => 'com']);
        $tld->prices()->create(['currency' => 'USD', 'years' => 1, 'register' => 1099, 'transfer' => 999, 'renew' => null, 'id_protection' => 0]);

        $this->post(self::INDEX.'/prices')->assertRedirect(self::INDEX)
            ->assertSessionHas('status', 'Converted from USD: 1 product prices, 1 option prices and 1 TLD prices.');

        $eur = ProductPrice::where(['product_id' => $product->id, 'currency' => 'EUR', 'billing_cycle' => self::MONTHLY])->first();
        $this->assertSame(1846, $eur->price);       // 1846.0765
        $this->assertSame(462, $eur->setup_fee);    // 461.75
        // Prices only in another currency are left alone.
        $this->assertSame(1, ProductPrice::where(['product_id' => $product->id, 'billing_cycle' => 'annually'])->value('price'));
        $this->assertSame(700, $only->prices()->value('price'));
        $this->assertSame(['EUR' => ['prices' => [self::MONTHLY => 277], 'setup_fee' => 139]], $choice->fresh()->currency_prices);
        $this->assertSame(277, $choice->fresh()->priceFor(BillingCycle::Monthly, 'EUR'));
        $tldEur = $tld->prices()->where('currency', 'EUR')->first();
        $this->assertSame([1015, 923, null], [$tldEur->register, $tldEur->transfer, $tldEur->renew]);
    }

    public function test_the_default_and_currencies_in_use_cannot_be_deleted(): void
    {
        $usd = Currency::where('code', 'USD')->first();
        $eur = Currency::create(['code' => 'EUR', 'rate' => '0.9']);
        $gbp = Currency::create(['code' => 'GBP', 'rate' => '0.8']);
        $cad = Currency::create(['code' => 'CAD', 'rate' => '1.3']);
        Client::factory()->create(['currency' => 'EUR']);
        $client = Client::factory()->create(['currency' => 'USD']);
        Invoice::create(['client_id' => $client->id, 'currency' => 'GBP', 'issue_date' => today(), 'due_date' => today()]);
        $product = Product::factory()->create();
        $product->prices()->create(['billing_cycle' => self::MONTHLY, 'currency' => 'CAD', 'price' => 100]);

        $this->delete(self::INDEX."/{$usd->id}")->assertSessionHasErrors(['currency' => 'USD is the default currency. Make another currency the default first.']);
        $this->delete(self::INDEX."/{$eur->id}")->assertSessionHasErrors('currency');
        $this->delete(self::INDEX."/{$gbp->id}")->assertSessionHasErrors('currency');
        $this->delete(self::INDEX."/{$cad->id}")->assertRedirect(self::INDEX);

        $this->assertSame(['USD', 'EUR', 'GBP'], Currency::codes());
        $this->assertFalse($product->prices()->exists());
    }

    public function test_making_another_currency_the_default_restates_the_rates(): void
    {
        $eur = Currency::create(['code' => 'EUR', 'rate' => '0.8']);
        Currency::create(['code' => 'GBP', 'rate' => '0.7']);
        $product = Product::factory()->create();
        $choice = $product->options()->create(['name' => 'Disk', 'type' => 'dropdown'])
            ->choices()->create(['name' => '50 GB', 'prices' => [self::MONTHLY => 300], 'setup_fee' => 100, 'currency_prices' => ['GBP' => ['prices' => [self::MONTHLY => 210], 'setup_fee' => 70]]]);

        $this->post(self::INDEX."/{$eur->id}/default")->assertRedirect(self::INDEX);

        $this->assertSame('EUR', config('billing.currency'));
        $this->assertSame(['EUR', 'GBP', 'USD'], Currency::codes());
        $this->assertSame('1.25', Currency::where('code', 'USD')->value('rate'));
        $this->assertSame('0.875', Currency::where('code', 'GBP')->value('rate'));
        // Option prices move: EUR converted from USD becomes the main price, USD keeps its own.
        $choice->refresh();
        $this->assertSame([self::MONTHLY => 240], $choice->prices);
        $this->assertSame(80, $choice->setup_fee);
        $this->assertSame(['prices' => [self::MONTHLY => 300], 'setup_fee' => 100], $choice->currency_prices['USD']);
        $this->assertSame(210, $choice->priceFor(BillingCycle::Monthly, 'GBP'));
    }

    public function test_a_clients_currency_can_only_change_before_they_are_billed(): void
    {
        Currency::create(['code' => 'EUR', 'rate' => '0.9']);
        $client = Client::factory()->create(['currency' => 'USD']);
        $details = ['first_name' => $client->first_name, 'last_name' => $client->last_name, 'email' => $client->email, 'status' => 'active'];

        $this->get("/admin/clients/{$client->id}/edit")->assertOk()->assertSee('<option value="EUR"', false);
        $this->put("/admin/clients/{$client->id}", $details + ['currency' => 'GBP'])->assertSessionHasErrors('currency');
        $this->put("/admin/clients/{$client->id}", $details + ['currency' => 'EUR'])->assertSessionHasNoErrors();
        $this->assertSame('EUR', $client->fresh()->currency);

        Invoice::create(['client_id' => $client->id, 'currency' => 'EUR', 'issue_date' => today(), 'due_date' => today()]);
        $this->get("/admin/clients/{$client->id}/edit")->assertSee("Can't be changed", false);
        $this->put("/admin/clients/{$client->id}", $details + ['currency' => 'USD'])
            ->assertSessionHasErrors(['currency' => "{$client->fullName()} already has invoices, payments or credit in EUR, so their currency can't be changed. Add a new client account for billing in USD."]);
        $this->assertSame('EUR', $client->fresh()->currency);
        // Leaving it as it is, or out, is fine.
        $this->put("/admin/clients/{$client->id}", $details + ['currency' => 'EUR'])->assertSessionHasNoErrors();
        $this->put("/admin/clients/{$client->id}", $details)->assertSessionHasNoErrors();

        $this->post('/admin/clients', ['first_name' => 'Bo', 'last_name' => 'Ng', 'email' => 'bo@example.com', 'status' => 'active', 'currency' => 'EUR']);
        $this->assertSame('EUR', Client::where('email', 'bo@example.com')->value('currency'));
    }

    public function test_the_dashboard_converts_other_currencies_to_the_default(): void
    {
        Currency::create(['code' => 'EUR', 'rate' => '0.8']);
        $client = Client::factory()->create();
        Transaction::create(['client_id' => $client->id, 'gateway' => 'stripe', 'currency' => 'USD', 'amount' => 1000]);
        Transaction::create(['client_id' => $client->id, 'gateway' => 'stripe', 'currency' => 'EUR', 'amount' => 800]);
        Transaction::create(['client_id' => $client->id, 'gateway' => 'stripe', 'currency' => 'XYZ', 'amount' => 999]);
        Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()])->forceFill(['total' => 500])->save();
        Invoice::create(['client_id' => $client->id, 'currency' => 'EUR', 'issue_date' => today(), 'due_date' => today()])->forceFill(['total' => 400])->save();

        $this->get('/admin')->assertOk()
            ->assertViewHas('incomeToday', 2000)
            ->assertViewHas('unpaidTotal', 1000)
            ->assertSee('other currencies converted');
    }

    public function test_product_prices_are_set_in_every_currency(): void
    {
        Currency::create(['code' => 'EUR', 'rate' => '0.9']);

        $this->get('/admin/products/create')->assertOk()->assertSee('currency_prices[EUR][monthly][price]', false);
        $this->post('/admin/products', [
            'name' => 'VPS', 'active' => '1',
            'prices' => [self::MONTHLY => ['price' => '10.00']],
            'currency_prices' => ['EUR' => [self::MONTHLY => ['price' => '9.50', 'setup_fee' => '2']]],
        ])->assertSessionHasNoErrors();

        $product = Product::where('name', 'VPS')->first();
        $this->assertSame(['USD' => 1000, 'EUR' => 950], $product->prices()->orderByDesc('currency')->pluck('price', 'currency')->all());
        $this->assertSame(200, $product->prices()->where('currency', 'EUR')->value('setup_fee'));
        $this->get("/admin/products/{$product->id}/edit")->assertSee('value="9.50"', false);

        // Blanking a currency's price stops selling that cycle in it.
        $this->put("/admin/products/{$product->id}", [
            'name' => 'VPS', 'active' => '1',
            'prices' => [self::MONTHLY => ['price' => '10.00']],
            'currency_prices' => ['EUR' => [self::MONTHLY => ['price' => '']]],
        ])->assertSessionHasNoErrors();
        $this->assertFalse($product->prices()->where('currency', 'EUR')->exists());
    }

    public function test_option_prices_are_set_in_every_currency(): void
    {
        Currency::create(['code' => 'EUR', 'rate' => '0.9']);
        $product = Product::factory()->create();
        $product->prices()->create(['billing_cycle' => self::MONTHLY, 'currency' => 'USD', 'price' => 1000]);
        $option = $product->options()->create(['name' => 'Backups', 'type' => 'checkbox']);
        $choice = $option->choices()->create(['name' => 'Backups']);

        $this->get("/admin/product-options/{$option->id}/edit")->assertOk()->assertSee('choices[0][currency_prices][EUR][prices][monthly]', false);
        $this->put("/admin/product-options/{$option->id}", ['name' => 'Backups', 'choices' => [[
            'id' => $choice->id, 'prices' => [self::MONTHLY => '3.00'],
            'currency_prices' => ['EUR' => ['prices' => [self::MONTHLY => '2.75'], 'setup_fee' => '']]],
        ]])->assertSessionHasNoErrors();

        $choice->refresh();
        $this->assertSame(300, $choice->priceFor(BillingCycle::Monthly));
        $this->assertSame(275, $choice->priceFor(BillingCycle::Monthly, 'EUR'));
        $this->assertTrue($choice->pricedIn('EUR'));
        $this->assertFalse($choice->pricedIn('GBP'));
    }

    public function test_the_tld_form_offers_every_currency(): void
    {
        Currency::create(['code' => 'EUR', 'rate' => '0.9']);

        $this->get('/admin/tlds/create')->assertOk()->assertSee('prices[EUR][1][register]', false);
    }
}
