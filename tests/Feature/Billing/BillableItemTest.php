<?php

namespace Tests\Feature\Billing;

use App\Billing\BillableItemBiller;
use App\Billing\InvoiceGenerator;
use App\Enums\InvoiceStatus;
use App\Models\BillableItem;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The billing run putting billable items on invoices, once each. */
class BillableItemTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.apply_credit_automatically' => false]);
        $this->client = Client::factory()->create();
    }

    private function item(array $attributes): BillableItem
    {
        return $this->client->billableItems()->create($attributes + ['description' => 'Website changes', 'amount' => 5000]);
    }

    private function bill(string $today)
    {
        return app(BillableItemBiller::class)->run(CarbonImmutable::parse($today));
    }

    public function test_items_set_for_the_next_run_are_invoiced_once(): void
    {
        $item = $this->item(['invoice_action' => BillableItem::NEXT_CRON, 'due_date' => '2026-10-25']);
        $this->item(['invoice_action' => BillableItem::DONT_INVOICE, 'description' => 'Not yet']);

        $invoices = $this->bill('2026-10-20');

        $this->assertCount(1, $invoices);
        $invoice = $invoices->first();
        $this->assertSame(5000, $invoice->total);
        $this->assertSame('2026-10-25', $invoice->due_date->toDateString());
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $line = $invoice->items->sole();
        $this->assertSame(InvoiceItem::TYPE_BILLABLE, $line->type);
        $this->assertSame('Website changes', $line->description);
        $this->assertSame($item->id, $line->billable_item_id);
        $this->assertSame($invoice->id, $item->refresh()->invoice_id);
        $this->assertTrue($item->isInvoiced());

        $this->assertCount(0, $this->bill('2026-10-20'));
        $this->assertCount(0, $this->bill('2026-10-21'));
        $this->assertSame(1, InvoiceItem::count());
    }

    public function test_an_item_for_the_next_run_with_a_past_due_date_is_due_today(): void
    {
        $this->item(['invoice_action' => BillableItem::NEXT_CRON, 'due_date' => '2026-10-01']);

        $this->assertSame('2026-10-20', $this->bill('2026-10-20')->first()->due_date->toDateString());
    }

    public function test_items_on_a_due_date_wait_until_it_is_within_the_invoicing_window(): void
    {
        $this->item(['invoice_action' => BillableItem::DUE_DATE, 'due_date' => '2026-11-10']);

        $this->assertCount(0, $this->bill('2026-11-02'));

        $invoice = $this->bill('2026-11-03')->sole();
        $this->assertSame('2026-11-10', $invoice->due_date->toDateString());
        $this->assertCount(0, $this->bill('2026-11-04'));
    }

    public function test_hours_at_a_rate_are_described_on_the_line(): void
    {
        $this->item(['invoice_action' => BillableItem::NEXT_CRON, 'description' => 'Consulting', 'hours' => '1.50', 'rate' => 7500, 'amount' => BillableItem::cost('1.5', 7500)]);

        $line = $this->bill('2026-10-20')->first()->items->sole();

        $this->assertSame(11250, $line->amount);
        $this->assertSame('Consulting (1.50 hours at $75.00/hr)', $line->description);
    }

    public function test_hours_cost_rounds_half_up_to_the_cent(): void
    {
        $this->assertSame(11250, BillableItem::cost('1.5', 7500));
        $this->assertSame(17, BillableItem::cost('0.33', 50));
        $this->assertSame(10000, BillableItem::cost('2', 5000));
    }

    public function test_untaxed_items_stay_untaxed(): void
    {
        $this->item(['invoice_action' => BillableItem::NEXT_CRON, 'taxable' => false]);

        $this->assertFalse($this->bill('2026-10-20')->first()->items->sole()->taxable);
    }

    public function test_items_are_grouped_into_one_invoice_per_client(): void
    {
        $this->item(['invoice_action' => BillableItem::NEXT_CRON]);
        $this->item(['invoice_action' => BillableItem::DUE_DATE, 'due_date' => '2026-10-22', 'amount' => 2500]);
        Client::factory()->create()->billableItems()->create(['description' => 'Other', 'amount' => 100, 'invoice_action' => BillableItem::NEXT_CRON]);

        $invoices = $this->bill('2026-10-20');

        $this->assertCount(2, $invoices);
        $this->assertSame(7500, $invoices->firstWhere('client_id', $this->client->id)->total);
    }

    public function test_recurring_items_are_invoiced_each_time_they_come_due_until_done(): void
    {
        $item = $this->item(['invoice_action' => BillableItem::RECUR, 'due_date' => '2026-01-31', 'recur_every' => 1, 'recur_unit' => 'month', 'recur_times' => 2]);

        $first = $this->bill('2026-01-24')->sole();
        $this->assertSame('Website changes (01/31/2026)', $first->items->sole()->description);
        $this->assertSame('2026-02-28', $item->refresh()->due_date->toDateString());
        $this->assertSame(1, $item->invoiced_count);
        $this->assertFalse($item->isInvoiced());
        $this->assertCount(0, $this->bill('2026-01-25'));

        $this->bill('2026-02-21')->sole();
        $this->assertTrue($item->refresh()->isFinished());
        $this->assertTrue($item->isInvoiced());
        $this->assertCount(0, $this->bill('2026-03-28'));
        $this->assertSame(2, $item->invoiceItems()->count());
    }

    public function test_items_recurring_for_ever_keep_going(): void
    {
        $item = $this->item(['invoice_action' => BillableItem::RECUR, 'due_date' => '2026-10-01', 'recur_every' => 2, 'recur_unit' => 'week']);

        foreach (['2026-10-01', '2026-10-15', '2026-10-29', '2026-11-12'] as $day) {
            $this->assertCount(1, $this->bill($day), $day);
        }

        $this->assertSame(4, $item->refresh()->invoiced_count);
        $this->assertSame('2026-11-26', $item->due_date->toDateString());
        $this->assertFalse($item->isInvoiced());
    }

    public function test_items_for_the_next_invoice_go_on_the_clients_renewal_invoice(): void
    {
        $item = $this->item(['invoice_action' => BillableItem::NEXT_INVOICE]);
        $due = $this->item(['invoice_action' => BillableItem::DUE_DATE, 'due_date' => '2026-10-31', 'amount' => 1000]);
        Service::factory()->for($this->client)->create(['recurring_amount' => 2000, 'next_due_date' => '2026-11-01']);

        // Nothing is due yet, and items for the next invoice wait for one.
        $this->assertCount(0, $this->bill('2026-10-20'));
        $this->assertCount(0, app(InvoiceGenerator::class)->generate(CarbonImmutable::parse('2026-10-20')));

        $invoice = app(InvoiceGenerator::class)->generate(CarbonImmutable::parse('2026-10-25'))->sole();

        $this->assertSame(8000, $invoice->total);
        $this->assertSame('2026-11-01', $invoice->due_date->toDateString());
        $this->assertSame($invoice->id, $item->refresh()->invoice_id);
        $this->assertSame($invoice->id, $due->refresh()->invoice_id);
        $this->assertCount(0, $this->bill('2026-10-25'));
    }

    public function test_the_nightly_billing_run_invoices_billable_items(): void
    {
        $this->item(['invoice_action' => BillableItem::NEXT_CRON]);

        $this->artisan('billing:run')->expectsOutput('Generated 1 invoice(s) for billable items.')->assertSuccessful();

        $this->assertSame(1, Invoice::count());
    }
}
