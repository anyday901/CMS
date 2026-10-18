<?php

namespace Tests\Feature\Billing;

use App\Billing\QuoteConverter;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\TaxRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxLevelsTest extends TestCase
{
    use RefreshDatabase;

    private const QST = 'QST (9.5%)';

    private const GST = 'GST (5%)';

    private const RULES_URL = '/admin/tax-rules';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Client::factory()->create(['country' => 'CA', 'state' => 'QC']);
        TaxRule::create(['name' => 'GST', 'rate' => 500, 'country' => 'CA']);
        TaxRule::create(['name' => 'QST', 'rate' => 950, 'level' => 2, 'country' => 'CA', 'state' => 'QC']);
    }

    public function test_one_level_works_out_the_same_as_before(): void
    {
        foreach ([0, 1, 5, 99, 1000, 1234, 99999, -1234] as $amount) {
            foreach ([0, 500, 825, 2000] as $rate) {
                $this->assertSame([TaxRule::taxOn($amount, $rate), 0], TaxRule::levels($amount, $rate));
                $this->assertSame([TaxRule::taxIncludedIn($amount, $rate), 0], TaxRule::levels($amount, $rate, 0, true, true));
            }
        }
    }

    public function test_each_level_is_charged_on_the_amount(): void
    {
        $invoice = $this->invoice(10000);

        $this->assertSame('GST', $invoice->tax_name);
        $this->assertSame('QST', $invoice->tax2_name);
        $this->assertFalse($invoice->tax2_compound);
        $this->assertSame(1450, $invoice->tax);
        $this->assertSame(950, $invoice->tax2);
        $this->assertSame(11450, $invoice->total);
        $this->assertSame([[self::GST, 500], [self::QST, 950]], $invoice->taxLines());
    }

    public function test_compound_level_2_is_charged_on_the_amount_plus_level_1(): void
    {
        config(['billing.tax_compound' => true]);

        $invoice = $this->invoice(10000);

        $this->assertTrue($invoice->tax2_compound);
        $this->assertSame(998, $invoice->tax2); // 9.5% of 105.00, rounded
        $this->assertSame(11498, $invoice->total);

        // Turning it off later doesn't rewrite the invoice.
        config(['billing.tax_compound' => false]);
        $this->assertSame(11498, $invoice->recalculate()->total);
    }

    public function test_tax_inclusive_prices_split_the_tax_inside_them_between_the_levels(): void
    {
        config(['billing.tax_inclusive' => true]);
        $invoice = $this->invoice(11450);
        $this->assertSame([[self::GST, 500], [self::QST, 950]], $invoice->taxLines());
        $this->assertSame(11450, $invoice->total);

        config(['billing.tax_compound' => true]);
        $invoice = $this->invoice(11498);
        $this->assertSame([[self::GST, 500], [self::QST, 998]], $invoice->taxLines());
        $this->assertSame(11498, $invoice->total);
    }

    public function test_a_client_with_only_a_level_2_rule_shows_one_tax_line(): void
    {
        $elsewhere = Client::factory()->create(['country' => 'US']);
        TaxRule::create(['name' => 'City tax', 'rate' => 100, 'level' => 2, 'country' => 'US']);

        $invoice = $this->invoice(10000, $elsewhere);

        $this->assertSame(0, $invoice->tax_rate);
        $this->assertSame([['City tax (1%)', 100]], $invoice->taxLines());
        $this->assertSame(10100, $invoice->total);
    }

    public function test_both_levels_show_on_the_invoice_and_its_pdf(): void
    {
        $invoice = $this->invoice(10000);
        $this->actingAs(User::factory()->create());

        $this->get("/admin/invoices/{$invoice->id}")->assertOk()->assertSeeInOrder([self::GST, '$5.00', self::QST, '$9.50', '$114.50']);
        $this->actingAs($this->client, 'client')->get("/portal/invoices/{$invoice->id}")->assertOk()->assertSeeInOrder([self::GST, self::QST]);

        $html = view('pdf.invoice', ['invoice' => $invoice, 'company' => config('billing.company')])->render();
        $this->assertStringContainsString(self::QST, $html);
    }

    public function test_a_quote_keeps_both_levels_when_it_becomes_an_invoice(): void
    {
        config(['billing.tax_compound' => true]);
        $quote = Quote::create(['client_id' => $this->client->id, 'subject' => 'Work', 'currency' => 'USD', 'issue_date' => today(), 'valid_until' => today()->addDays(30)]);
        $quote->items()->create(['description' => 'Work', 'amount' => 10000]);
        $quote->recalculate();
        TaxRule::where('level', 2)->update(['rate' => 1000]);

        $invoice = app(QuoteConverter::class)->convert($quote);

        $this->assertSame(['QST', 950, true], [$invoice->tax2_name, $invoice->tax2_rate, $invoice->tax2_compound]);
        $this->assertSame($quote->total, $invoice->total);
    }

    public function test_admin_sets_a_rules_level_and_compound_tax(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(self::RULES_URL, ['name' => 'PST', 'rate' => '7', 'level' => '2', 'country' => 'CA', 'state' => 'BC'])->assertRedirect(self::RULES_URL);
        $this->assertSame(2, TaxRule::where('name', 'PST')->sole()->level);
        $this->post(self::RULES_URL, ['name' => 'Bad', 'rate' => '1', 'level' => '3'])->assertSessionHasErrors('level');
        $this->get(self::RULES_URL)->assertOk()->assertSee('Level 2');

        $this->put('/admin/settings', ['tax_compound' => '1'])->assertRedirect();
        $this->assertTrue(config('billing.tax_compound'));
    }

    public function test_the_tax_report_shows_each_level(): void
    {
        $this->invoice(10000)->forceFill(['status' => InvoiceStatus::Paid, 'paid_at' => '2026-09-10 12:00:00'])->save();
        $this->actingAs(User::factory()->create());

        $csv = $this->get('/admin/reports/tax-collected/export?from=2026-09-01&to=2026-09-30')->streamedContent();

        $this->assertStringContainsString('Country,Tax,Rate,"Level 2 tax","Level 2 rate",Invoices,"Sales before tax","Level 1","Level 2",Tax', $csv);
        $this->assertStringContainsString('CA,GST,5.0,QST,9.5,1,100.00,5.00,9.50,14.50', $csv);
    }

    private function invoice(int $amount, ?Client $client = null): Invoice
    {
        $client ??= $this->client;
        $invoice = Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $invoice->items()->create(['description' => 'Work', 'amount' => $amount]);

        return $invoice->recalculate();
    }
}
