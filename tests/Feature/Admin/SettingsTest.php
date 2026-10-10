<?php

namespace Tests\Feature\Admin;

use App\Billing\LateFeeProcessor;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\TaxRule;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/settings';

    private function valid(array $overrides = []): array
    {
        return $overrides + ['late_fee_after_days' => '5', 'late_fee_type' => 'fixed', 'late_fee_amount' => '7.50', 'tax_inclusive' => '0'];
    }

    public function test_admins_save_late_fee_settings_that_the_billing_run_uses(): void
    {
        $this->actingAs(User::factory()->create(), 'web');

        $this->get(self::URL)->assertOk()->assertSee('Late fees');
        $this->put(self::URL, $this->valid())->assertRedirect(self::URL);

        $this->assertSame('5', (string) Setting::find('billing.late_fee_after_days')->value);
        $this->assertSame('7.50', config('billing.late_fee_amount'));

        $client = Client::factory()->create();
        $invoice = Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => '2026-10-01', 'due_date' => '2026-10-01']);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => 1000]);
        $invoice->recalculate();

        app(LateFeeProcessor::class)->run(CarbonImmutable::parse('2026-10-06'));
        $this->assertSame(1750, $invoice->fresh()->total);
    }

    public function test_saved_settings_override_config_on_the_next_request(): void
    {
        Settings::save(['billing.late_fee_type' => 'percent', 'billing.tax_inclusive' => true]);
        config(['billing.late_fee_type' => 'fixed', 'billing.tax_inclusive' => false]);

        Settings::apply();

        $this->assertSame('percent', config('billing.late_fee_type'));
        $this->assertTrue(config('billing.tax_inclusive'));
    }

    public function test_blank_days_turn_late_fees_off_and_bad_values_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(), 'web');

        $this->put(self::URL, $this->valid(['late_fee_after_days' => '']))->assertSessionDoesntHaveErrors();
        $this->assertNull(config('billing.late_fee_after_days'));

        $this->put(self::URL, $this->valid(['late_fee_type' => 'percent', 'late_fee_amount' => '150']))->assertSessionHasErrors('late_fee_amount');
        $this->put(self::URL, $this->valid(['late_fee_amount' => '0']))->assertSessionHasErrors('late_fee_amount');
        $this->put(self::URL, $this->valid(['late_fee_type' => 'weekly']))->assertSessionHasErrors('late_fee_type');
    }

    public function test_only_admins_reach_settings(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'billing']), 'web');

        $this->get(self::URL)->assertForbidden();
        $this->put(self::URL, $this->valid())->assertForbidden();
    }

    public function test_tax_inclusive_invoices_take_the_tax_out_of_the_price(): void
    {
        TaxRule::create(['name' => 'VAT', 'rate' => 2000]);
        $client = Client::factory()->create();

        config(['billing.tax_inclusive' => true]);
        $inclusive = Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $inclusive->items()->create(['description' => 'Hosting', 'amount' => 1200, 'taxable' => true]);
        $inclusive->items()->create(['description' => 'Late fee', 'amount' => 500, 'taxable' => false]);
        $inclusive->recalculate();

        $this->assertTrue($inclusive->tax_inclusive);
        $this->assertSame(1700, $inclusive->total);
        $this->assertSame(200, $inclusive->tax); // 12.00 includes 2.00 of 20% VAT

        // Changing the setting later leaves existing invoices alone.
        config(['billing.tax_inclusive' => false]);
        $inclusive->recalculate();
        $this->assertSame(1700, $inclusive->fresh()->total);

        $exclusive = Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $exclusive->items()->create(['description' => 'Hosting', 'amount' => 1200, 'taxable' => true]);
        $this->assertSame(1440, $exclusive->recalculate()->total);

        $this->actingAs($client, 'client');
        $this->get("/portal/invoices/{$inclusive->id}")->assertSee('Includes VAT (20%)')->assertSee('$2.00');
    }

    public function test_included_tax_rounds_to_the_nearest_cent(): void
    {
        $this->assertSame(85, TaxRule::taxIncludedIn(999, 925)); // 9.99 at 9.25% holds 0.8458
        $this->assertSame(-85, TaxRule::taxIncludedIn(-999, 925));
        $this->assertSame(0, TaxRule::taxIncludedIn(1000, 0));
    }
}
