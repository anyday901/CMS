<?php

namespace Tests\Feature\Billing;

use App\Billing\InvoiceGenerator;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function generate(string $today)
    {
        return app(InvoiceGenerator::class)->generate(CarbonImmutable::parse($today));
    }

    public function test_invoices_services_due_within_the_window(): void
    {
        $service = Service::factory()->create(['next_due_date' => '2026-11-01', 'recurring_amount' => 1599]);

        $this->assertCount(0, $this->generate('2026-10-24'));

        $invoices = $this->generate('2026-10-25');

        $this->assertCount(1, $invoices);
        $invoice = $invoices->first();
        $this->assertSame(1599, $invoice->total);
        $this->assertSame('2026-11-01', $invoice->due_date->toDateString());
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);

        $item = $invoice->items->first();
        $this->assertSame($service->id, $item->service_id);
        $this->assertSame('2026-11-01', $item->period_start->toDateString());
        $this->assertSame('2026-11-30', $item->period_end->toDateString());
    }

    public function test_running_twice_does_not_duplicate(): void
    {
        Service::factory()->create();

        $this->assertCount(1, $this->generate('2026-10-30'));
        $this->assertCount(0, $this->generate('2026-10-30'));
        $this->assertCount(0, $this->generate('2026-10-31'));
    }

    public function test_cancelled_invoice_period_is_reinvoiced(): void
    {
        Service::factory()->create();

        $this->generate('2026-10-30')->first()->update(['status' => InvoiceStatus::Cancelled]);

        $this->assertCount(1, $this->generate('2026-10-31'));
    }

    public function test_groups_services_into_one_invoice_per_client(): void
    {
        $client = Client::factory()->create();
        Service::factory()->for($client)->create(['recurring_amount' => 1000, 'next_due_date' => '2026-11-03']);
        Service::factory()->for($client)->create(['recurring_amount' => 500, 'next_due_date' => '2026-11-01']);
        Service::factory()->create();

        $invoices = $this->generate('2026-10-30');

        $this->assertCount(2, $invoices);
        $mine = $invoices->firstWhere('client_id', $client->id);
        $this->assertSame(1500, $mine->total);
        $this->assertCount(2, $mine->items);
        $this->assertSame('2026-11-01', $mine->due_date->toDateString());
    }

    public function test_skips_one_time_and_inactive_services(): void
    {
        Service::factory()->create(['billing_cycle' => BillingCycle::OneTime]);
        Service::factory()->create(['status' => ServiceStatus::Terminated]);
        Service::factory()->create(['status' => ServiceStatus::Cancelled]);
        Service::factory()->pending()->create();

        $this->assertCount(0, $this->generate('2026-10-30'));
    }
}
