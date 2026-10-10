<?php

namespace Tests\Feature\Billing;

use App\Billing\LateFeeProcessor;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\TaxRule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LateFeeTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(int $amount = 2000): Invoice
    {
        $invoice = Invoice::create(['client_id' => Client::factory()->create()->id, 'currency' => 'USD', 'issue_date' => '2026-11-01', 'due_date' => '2026-11-01']);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => $amount]);

        return $invoice->recalculate();
    }

    private function runOn(string $date): int
    {
        return app(LateFeeProcessor::class)->run(CarbonImmutable::parse($date));
    }

    public function test_off_by_default(): void
    {
        $this->invoice();
        $this->assertSame(0, $this->runOn('2027-01-01'));
    }

    public function test_fixed_fee_added_once_after_the_grace_days(): void
    {
        config(['billing.late_fee_after_days' => 5, 'billing.late_fee_type' => 'fixed', 'billing.late_fee_amount' => '5.00']);
        $invoice = $this->invoice();

        $this->assertSame(0, $this->runOn('2026-11-05'));
        $this->assertSame(1, $this->runOn('2026-11-06'));
        $this->assertSame(0, $this->runOn('2026-11-07'));

        $invoice->refresh();
        $this->assertSame(2500, $invoice->total);
        $this->assertSame(1, $invoice->items()->where('type', InvoiceItem::TYPE_LATE_FEE)->count());
    }

    public function test_percent_fee_is_not_taxed_and_skips_paid_invoices(): void
    {
        config(['billing.late_fee_after_days' => 0, 'billing.late_fee_type' => 'percent', 'billing.late_fee_amount' => '10']);
        TaxRule::create(['name' => 'VAT', 'rate' => 2000]);
        $invoice = $this->invoice(1000); // 10.00 + 2.00 tax
        $paid = $this->invoice();
        $paid->forceFill(['status' => 'paid'])->save();

        $this->assertSame(1, $this->runOn('2026-11-02'));

        $invoice->refresh();
        $this->assertSame(200, $invoice->tax);
        $this->assertSame(1200 + 120, $invoice->total);
        $this->assertSame(2400, $paid->refresh()->total); // 20.00 + 4.00 tax, no late fee
    }
}
