<?php

namespace Tests\Feature\Billing;

use App\Billing\InvoiceGenerator;
use App\Billing\OrderService;
use App\Enums\BillingCycle;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\TaxRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxTest extends TestCase
{
    use RefreshDatabase;

    private const DUE = '2026-11-01';

    private const RULES_URL = '/admin/tax-rules';

    public function test_most_specific_rule_wins(): void
    {
        TaxRule::create(['name' => 'Everywhere', 'rate' => 100]);
        TaxRule::create(['name' => 'US', 'rate' => 500, 'country' => 'US']);
        TaxRule::create(['name' => 'Texas', 'rate' => 825, 'country' => 'US', 'state' => 'TX']);

        $this->assertSame('Texas', TaxRule::forClient(Client::factory()->make(['country' => 'us', 'state' => 'tx']))->name);
        $this->assertSame('US', TaxRule::forClient(Client::factory()->make(['country' => 'US', 'state' => 'OH']))->name);
        $this->assertSame('Everywhere', TaxRule::forClient(Client::factory()->make(['country' => 'CA']))->name);
        $this->assertNull(TaxRule::forClient(Client::factory()->make(['country' => 'US', 'state' => 'TX', 'tax_exempt' => true])));
    }

    public function test_renewal_invoice_taxes_only_taxable_items(): void
    {
        TaxRule::create(['name' => 'Sales tax', 'rate' => 825, 'country' => 'US']);
        $client = Client::factory()->create(['country' => 'US']);
        Service::factory()->for($client)->create(['recurring_amount' => 1000, 'next_due_date' => self::DUE]);
        $untaxed = Product::factory()->create(['taxable' => false]);
        Service::factory()->for($client)->for($untaxed)->create(['recurring_amount' => 2000, 'next_due_date' => self::DUE]);

        $invoice = app(InvoiceGenerator::class)->generate(CarbonImmutable::parse(self::DUE))->sole();

        $this->assertSame('Sales tax', $invoice->tax_name);
        $this->assertSame(825, $invoice->tax_rate);
        $this->assertSame(3000, $invoice->subtotal);
        $this->assertSame(83, $invoice->tax); // 8.25% of 10.00, rounded
        $this->assertSame(3083, $invoice->total);
        $this->assertSame('Sales tax (8.25%)', $invoice->taxLabel());
    }

    public function test_exempt_client_and_no_rules_mean_no_tax(): void
    {
        $product = Product::factory()->create();
        $product->prices()->create(['billing_cycle' => BillingCycle::Monthly, 'currency' => 'USD', 'price' => 1000]);

        $order = app(OrderService::class)->create(Client::factory()->create(), $product, BillingCycle::Monthly, today());
        $this->assertSame(0, $order['invoice']->tax);

        TaxRule::create(['name' => 'VAT', 'rate' => 2000]);
        $exempt = Client::factory()->create(['tax_exempt' => true]);
        $order = app(OrderService::class)->create($exempt, $product, BillingCycle::Monthly, today());
        $this->assertSame(0, $order['invoice']->tax);
        $this->assertSame(1000, $order['invoice']->total);
    }

    public function test_rule_changes_do_not_rewrite_existing_invoices(): void
    {
        $rule = TaxRule::create(['name' => 'VAT', 'rate' => 2000]);
        $invoice = Invoice::create(['client_id' => Client::factory()->create()->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $invoice->items()->create(['description' => 'Work', 'amount' => 1000]);
        $invoice->recalculate();

        $rule->update(['rate' => 1000]);
        $invoice->recalculate();

        $this->assertSame(200, $invoice->tax);
    }

    public function test_admin_manages_tax_rules(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(self::RULES_URL, ['name' => 'Texas', 'rate' => '8.25', 'country' => 'us', 'state' => 'TX'])
            ->assertRedirect(self::RULES_URL);
        $rule = TaxRule::sole();
        $this->assertSame(825, $rule->rate);
        $this->assertSame('US', $rule->country);

        $this->get(self::RULES_URL)->assertSee('Texas')->assertSee('8.25%');
        $this->post(self::RULES_URL, ['name' => 'Bad', 'rate' => '101'])->assertSessionHasErrors('rate');
        $this->post(self::RULES_URL, ['name' => 'No country', 'rate' => '5', 'state' => 'CA'])->assertSessionHasErrors('country');

        $this->put(self::RULES_URL."/{$rule->id}", ['name' => 'Texas', 'rate' => '6.25', 'country' => 'US', 'state' => 'TX']);
        $this->assertSame(625, $rule->refresh()->rate);

        $this->delete(self::RULES_URL."/{$rule->id}");
        $this->assertSame(0, TaxRule::count());
    }
}
