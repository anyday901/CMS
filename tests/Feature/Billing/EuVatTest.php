<?php

namespace Tests\Feature\Billing;

use App\Billing\VatNumbers;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\TaxRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EuVatTest extends TestCase
{
    use RefreshDatabase;

    private const OUR_VAT = 'DE123456789';

    private const THEIR_VAT = 'FR12345678901';

    private const PASSWORD = 'a-long-password';

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.company.tax_id' => self::OUR_VAT, 'billing.reverse_charge' => true]);
        TaxRule::create(['name' => 'VAT', 'rate' => 2000]);
    }

    public function test_vies_confirms_a_vat_number_staff_enter(): void
    {
        $this->vies(['valid' => true]);
        $this->actingAs(User::factory()->create());

        $this->post('/admin/clients', [
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com', 'status' => 'active', 'tax_id' => 'fr 12 345 678 901',
        ])->assertRedirect();

        $client = Client::sole();
        $this->assertSame(self::THEIR_VAT, $client->tax_id);
        $this->assertNotNull($client->tax_id_verified_at);
        Http::assertSent(fn (Request $request) => $request->url() === VatNumbers::VIES_URL && $request['countryCode'] === 'FR' && $request['vatNumber'] === '12345678901');
        $this->get("/admin/clients/{$client->id}")->assertSee(self::THEIR_VAT.' (VIES verified)')->assertSee('Reverse charge');

        // Saving the same number again doesn't ask VIES again.
        $this->put("/admin/clients/{$client->id}", ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com', 'status' => 'active', 'tax_id' => self::THEIR_VAT]);
        Http::assertSentCount(1);
    }

    public function test_a_number_vies_says_does_not_exist_is_refused(): void
    {
        $this->vies(['valid' => false]);
        $client = Client::factory()->create();

        $this->actingAs($client, 'client')->put('/portal/account', ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => $client->email, 'tax_id' => self::THEIR_VAT])
            ->assertSessionHasErrors('tax_id');
        $this->assertNull($client->refresh()->tax_id);
    }

    public function test_a_number_vies_cannot_read_counts_as_invalid(): void
    {
        $this->vies(['actionSucceed' => false, 'errorWrappers' => [['error' => 'INVALID_INPUT']]], 400);

        $this->assertFalse(app(VatNumbers::class)->check(self::THEIR_VAT));
    }

    public function test_vies_being_down_never_blocks_checkout(): void
    {
        Mail::fake();
        $this->vies(['actionSucceed' => false, 'errorWrappers' => [['error' => 'MS_UNAVAILABLE']]], 500);
        $product = Product::factory()->create();
        $product->prices()->create(['billing_cycle' => 'monthly', 'currency' => 'USD', 'price' => 1000]);

        $this->post(route('cart.store', $product), ['billing_cycle' => 'monthly']);
        $this->post('/checkout', [
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
            'address1' => '1 Rue', 'city' => 'Paris', 'postcode' => '75001', 'country' => 'fr', 'tax_id' => self::THEIR_VAT,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $client = Client::sole();
        $this->assertSame(self::THEIR_VAT, $client->tax_id);
        $this->assertNull($client->tax_id_verified_at);
        // Unverified numbers don't get reverse charge, so VAT is charged until VIES confirms it.
        $invoice = Order::sole()->invoice;
        $this->assertFalse($invoice->reverse_charge);
        $this->assertSame(200, $invoice->tax);
        $this->assertSame(self::THEIR_VAT, $invoice->client_tax_id);
    }

    public function test_a_connection_failure_counts_as_vies_being_down(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->assertNull(app(VatNumbers::class)->check(self::THEIR_VAT));
        $this->assertSame(['tax_id' => self::THEIR_VAT, 'tax_id_verified_at' => null], app(VatNumbers::class)->attributes(self::THEIR_VAT));
    }

    public function test_other_tax_ids_and_turned_off_checks_never_call_vies(): void
    {
        Http::fake();

        $this->assertSame(['tax_id' => '12-3456789', 'tax_id_verified_at' => null], app(VatNumbers::class)->attributes(' 12-3456789 '));
        $this->assertNull(VatNumbers::prefix('CHE-123.456.789'));
        $this->assertSame('EL', VatNumbers::prefix('el 123456789'));

        config(['billing.reverse_charge' => false, 'billing.vat_validation' => false]);
        $this->assertSame(['tax_id' => self::THEIR_VAT, 'tax_id_verified_at' => null], app(VatNumbers::class)->attributes(self::THEIR_VAT));
        Http::assertNothingSent();
    }

    public function test_a_business_in_another_eu_country_is_reverse_charged(): void
    {
        $client = $this->verifiedClient(self::THEIR_VAT);

        $invoice = $this->invoice($client);

        $this->assertTrue($invoice->reverse_charge);
        $this->assertSame(0, $invoice->tax);
        $this->assertSame(10000, $invoice->total);
        $this->assertSame(self::THEIR_VAT, $invoice->client_tax_id);

        $html = view('pdf.invoice', ['invoice' => $invoice, 'company' => config('billing.company')])->render();
        $this->assertStringContainsString('Reverse charge', $html);
        $this->assertStringContainsString('Supplier VAT number: '.self::OUR_VAT, $html);
        $this->assertStringContainsString('Customer VAT number: '.self::THEIR_VAT, $html);

        $this->actingAs($client, 'client')->get("/portal/invoices/{$invoice->id}")->assertOk()
            ->assertSee('Reverse charge')->assertSee(self::OUR_VAT)->assertSee(self::THEIR_VAT);
    }

    public function test_vat_is_charged_at_home_without_the_setting_or_when_exempt(): void
    {
        $this->assertSame(2000, $this->invoice($this->verifiedClient('DE999999999'))->tax);

        $foreign = $this->verifiedClient(self::THEIR_VAT);
        config(['billing.reverse_charge' => false]);
        $invoice = $this->invoice($foreign);
        $this->assertFalse($invoice->reverse_charge);
        $this->assertSame(2000, $invoice->tax);

        config(['billing.reverse_charge' => true, 'billing.company.tax_id' => 'US-EIN 12-3456789']);
        $this->assertSame(2000, $this->invoice($foreign)->tax);
    }

    public function test_the_tax_report_lists_reverse_charge_sales(): void
    {
        $this->invoice($this->verifiedClient(self::THEIR_VAT, 'FR'))->forceFill(['status' => 'paid', 'paid_at' => '2026-09-10 12:00:00'])->save();
        $this->actingAs(User::factory()->create());

        $csv = $this->get('/admin/reports/tax-collected/export?from=2026-09-01&to=2026-09-30')->streamedContent();

        $this->assertStringContainsString('FR,"Reverse charge",0.0,1,100.00,0.00', $csv);
    }

    public function test_settings_turn_checking_and_reverse_charge_on(): void
    {
        config(['billing.reverse_charge' => false]);
        $this->actingAs(User::factory()->create());

        $this->get('/admin/settings')->assertOk()->assertSee('Reverse charge EU VAT');
        $this->put('/admin/settings', ['vat_validation' => '1', 'reverse_charge' => '1'])->assertRedirect();

        $this->assertTrue(config('billing.vat_validation'));
        $this->assertTrue(config('billing.reverse_charge'));
        $this->assertTrue(VatNumbers::checking());
    }

    private function vies(array $body, int $status = 200): void
    {
        Http::fake([VatNumbers::VIES_URL => Http::response($body, $status)]);
    }

    private function verifiedClient(string $vatNumber, string $country = 'FR'): Client
    {
        return Client::factory()->create(['country' => $country, 'company' => 'Acme', 'tax_id' => $vatNumber, 'tax_id_verified_at' => now()]);
    }

    private function invoice(Client $client): Invoice
    {
        $invoice = Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => 10000]);

        return $invoice->recalculate();
    }
}
