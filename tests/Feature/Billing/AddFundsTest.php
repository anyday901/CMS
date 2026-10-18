<?php

namespace Tests\Feature\Billing;

use App\Billing\CreditApplier;
use App\Billing\PaymentRecorder;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\TaxRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddFundsTest extends TestCase
{
    use RefreshDatabase;

    private const SETTINGS = '/admin/settings';

    private const PAGE = '/portal/add-funds';

    private function client(int $credit = 0): Client
    {
        $client = Client::factory()->create(['country' => 'US']);
        $client->forceFill(['credit_balance' => $credit])->save();
        $this->actingAs($client, 'client');

        return $client;
    }

    private function enable(array $limits = []): void
    {
        config(['billing.add_funds' => $limits + ['enabled' => true, 'minimum' => '10.00', 'maximum' => '500.00', 'maximum_balance' => '1000.00']]);
    }

    public function test_add_funds_is_off_until_turned_on(): void
    {
        $this->client();

        $this->get(self::PAGE)->assertNotFound();
        $this->post(self::PAGE, ['amount' => '25.00'])->assertNotFound();
        $this->get('/portal/invoices')->assertDontSee('Add funds');
    }

    public function test_a_paid_add_funds_invoice_adds_to_the_credit_balance(): void
    {
        // Not taxed, even where the client pays tax.
        TaxRule::create(['name' => 'Sales tax', 'rate' => 825, 'country' => 'US']);
        $this->enable();
        $client = $this->client();

        $this->get('/portal/invoices')->assertSee('Add funds');
        $this->get(self::PAGE)->assertOk()->assertSee('Between $10.00 and $500.00.');
        $response = $this->post(self::PAGE, ['amount' => '25.00']);

        $invoice = Invoice::sole();
        $response->assertRedirect("/portal/invoices/{$invoice->id}");
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame(2500, $invoice->total);
        $this->assertSame(0, $invoice->tax);
        $this->assertTrue($invoice->isAddFunds());
        $this->get("/portal/invoices/{$invoice->id}")->assertSee('Once this invoice is paid, the amount is added to your account credit.');

        app(PaymentRecorder::class)->record($invoice, 2500, 'paypal', 'PAYPAL-1');

        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertSame(2500, $client->refresh()->credit_balance);
        $this->assertSame("Added funds with invoice #{$invoice->number}", $client->creditEntries()->sole()->description);
        $this->assertSame('paypal', $invoice->transactions()->sole()->gateway);

        // A retried gateway callback adds nothing more.
        app(PaymentRecorder::class)->record($invoice, 2500, 'paypal', 'PAYPAL-1');
        $this->assertSame(2500, $client->refresh()->credit_balance);
    }

    public function test_limits_on_each_payment_and_on_the_balance(): void
    {
        $this->enable();
        $client = $this->client(credit: 85000);

        $this->post(self::PAGE, ['amount' => '5'])->assertSessionHasErrors(['amount' => 'The least you can add is $10.00.']);
        $this->post(self::PAGE, ['amount' => 'lots'])->assertSessionHasErrors(['amount' => 'Enter an amount like 25.00.']);
        $this->post(self::PAGE, ['amount' => '200.00'])->assertSessionHasErrors(['amount' => 'The most you can add now is $150.00.']);

        $this->post(self::PAGE, ['amount' => '100.00'])->assertSessionHasNoErrors();
        // The unpaid add funds invoice counts towards the balance limit.
        $this->post(self::PAGE, ['amount' => '60.00'])->assertSessionHasErrors(['amount' => 'The most you can add now is $50.00.']);

        $client->forceFill(['credit_balance' => 99500])->save();
        $this->get(self::PAGE)->assertSee('Your account credit is at its limit of $1,000.00, so no more can be added.');
        $this->assertSame(1, Invoice::count());

        // Blank limits mean none.
        $this->enable(['maximum' => null, 'maximum_balance' => '']);
        $this->post(self::PAGE, ['amount' => '5000.00'])->assertSessionHasNoErrors();
    }

    public function test_add_funds_invoices_are_not_paid_from_credit_or_chased(): void
    {
        $this->enable();
        $client = $this->client(credit: 5000);
        $this->post(self::PAGE, ['amount' => '20.00']);
        $invoice = Invoice::sole();

        $this->assertNull(app(CreditApplier::class)->apply($invoice));
        $this->get("/portal/invoices/{$invoice->id}")->assertDontSee('account credit</button>', false);
        $this->assertSame(0, Invoice::withoutFunds()->count());
        $this->assertSame(5000, $client->refresh()->credit_balance);
    }

    public function test_staff_change_the_add_funds_settings(): void
    {
        $this->actingAs(User::factory()->create(), 'web');

        $this->get(self::SETTINGS)->assertOk()->assertSee('Add funds and mass payment');
        $this->put(self::SETTINGS, ['add_funds_enabled' => '1', 'add_funds_minimum' => '5', 'add_funds_maximum' => '', 'add_funds_maximum_balance' => '250.00', 'mass_pay' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(config('billing.add_funds.enabled'));
        $this->assertSame('5.00', config('billing.add_funds.minimum'));
        $this->assertNull(config('billing.add_funds.maximum'));
        $this->assertSame('250.00', config('billing.add_funds.maximum_balance'));
        $this->assertFalse(config('billing.mass_pay'));

        $this->put(self::SETTINGS, ['add_funds_minimum' => '50', 'add_funds_maximum' => '20'])
            ->assertSessionHasErrors(['add_funds_maximum']);
    }

    public function test_add_funds_lines_are_not_counted_as_product_income(): void
    {
        $this->enable();
        $this->client();
        $this->post(self::PAGE, ['amount' => '30.00']);
        $invoice = Invoice::sole();
        app(PaymentRecorder::class)->record($invoice, 3000, 'paypal');

        $this->assertSame(1, InvoiceItem::where('type', InvoiceItem::TYPE_ADD_FUNDS)->count());
        $this->actingAs(User::factory()->create(), 'web');
        $this->get('/admin/reports/income-by-product?from='.today()->toDateString().'&to='.today()->toDateString())
            ->assertOk()->assertDontSee('Other items');
    }
}
