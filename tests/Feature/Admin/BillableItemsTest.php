<?php

namespace Tests\Feature\Admin;

use App\Billing\BillableItemBiller;
use App\Clients\ClientMerger;
use App\Enums\InvoiceStatus;
use App\Models\BillableItem;
use App\Models\Client;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Billable items on the client page: added, changed until invoiced, and shown with where they were billed. */
class BillableItemsTest extends TestCase
{
    use RefreshDatabase;

    private const DUE = '2026-10-20';

    private const NEXT_DAY = '2026-10-21';

    private const LOGO = 'Logo design';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.apply_credit_automatically' => false]);
        $this->actingAs(User::factory()->create());
        $this->client = Client::factory()->create();
    }

    private function store(array $data)
    {
        return $this->post("/admin/clients/{$this->client->id}/billable-items", $data + [
            'description' => 'Website changes', 'invoice_action' => BillableItem::NEXT_CRON, 'due_date' => self::DUE, 'taxable' => '1',
        ]);
    }

    public function test_staff_add_an_item_with_an_amount(): void
    {
        $this->store(['amount' => '50.00'])
            ->assertRedirect("/admin/clients/{$this->client->id}?tab=billable-items")
            ->assertSessionHasNoErrors();

        $item = $this->client->billableItems()->sole();
        $this->assertSame(5000, $item->amount);
        $this->assertNull($item->hours);
        $this->assertTrue($item->taxable);
        $this->assertSame(BillableItem::NEXT_CRON, $item->invoice_action);
        $this->assertNotNull($item->user_id);

        $this->get("/admin/clients/{$this->client->id}?tab=billable-items")
            ->assertOk()
            ->assertSee('data-section-link="billable-items"', false)
            ->assertSeeInOrder(['Website changes', '$50.00', 'Invoice on the next billing run', 'Not yet']);
    }

    public function test_hours_and_a_rate_work_out_the_amount(): void
    {
        $this->store(['hours' => '2.5', 'rate' => '80.00', 'amount' => ''])->assertSessionHasNoErrors();

        $item = $this->client->billableItems()->sole();
        $this->assertSame(20000, $item->amount);
        $this->assertSame('2.50', $item->hours);
        $this->assertSame(8000, $item->rate);
    }

    public function test_the_form_is_checked(): void
    {
        $this->store([])->assertSessionHasErrors('amount');
        $this->store(['hours' => '2'])->assertSessionHasErrors('rate');
        $this->store(['hours' => 'two', 'rate' => '10'])->assertSessionHasErrors('hours');
        $this->store(['amount' => '0'])->assertSessionHasErrors('amount');
        $this->store(['amount' => '5', 'invoice_action' => BillableItem::DUE_DATE, 'due_date' => ''])->assertSessionHasErrors('due_date');
        $this->store(['amount' => '5', 'invoice_action' => BillableItem::RECUR, 'recur_unit' => 'fortnight'])->assertSessionHasErrors(['recur_every', 'recur_unit']);
        $this->store(['amount' => '5', 'invoice_action' => 'sometime'])->assertSessionHasErrors('invoice_action');

        $this->assertSame(0, BillableItem::count());
    }

    public function test_a_recurring_item_keeps_its_schedule(): void
    {
        $this->store(['amount' => '10', 'invoice_action' => BillableItem::RECUR, 'recur_every' => '3', 'recur_unit' => 'month', 'recur_times' => '4'])->assertSessionHasNoErrors();
        $this->store(['amount' => '10', 'description' => 'Backups', 'invoice_action' => BillableItem::RECUR, 'recur_every' => '1', 'recur_unit' => 'year', 'recur_times' => ''])->assertSessionHasNoErrors();

        [$quarterly, $yearly] = $this->client->billableItems()->orderBy('id')->get();
        $this->assertSame([3, 'month', 4], [$quarterly->recur_every, $quarterly->recur_unit, $quarterly->recur_times]);
        $this->assertNull($yearly->recur_times);

        $this->get("/admin/clients/{$this->client->id}?tab=billable-items")
            ->assertSee('Every 3 months, 0 of 4 invoiced')
            ->assertSee('Every year, 0 invoiced so far');
    }

    public function test_items_can_be_changed_and_deleted_until_invoiced(): void
    {
        $this->store(['amount' => '50.00', 'invoice_action' => BillableItem::DONT_INVOICE]);
        $item = $this->client->billableItems()->sole();

        $this->put("/admin/billable-items/{$item->id}", ['description' => self::LOGO, 'amount' => '75.00', 'invoice_action' => BillableItem::NEXT_CRON, 'due_date' => self::DUE])
            ->assertSessionHasNoErrors();
        $this->assertSame([self::LOGO, 7500, false], [$item->refresh()->description, $item->amount, $item->taxable]);

        $invoice = app(BillableItemBiller::class)->run(CarbonImmutable::parse(self::DUE))->sole();

        $this->get("/admin/clients/{$this->client->id}?tab=billable-items")
            ->assertSee("Invoice #{$invoice->number}")
            ->assertDontSee("/admin/billable-items/{$item->id}");
        $this->put("/admin/billable-items/{$item->id}", ['description' => 'Changed', 'amount' => '1', 'invoice_action' => BillableItem::NEXT_CRON])
            ->assertSessionHasErrors('item');
        $this->delete("/admin/billable-items/{$item->id}")->assertSessionHasErrors('item');
        $this->assertSame(self::LOGO, $item->refresh()->description);

        // Cancelling the invoice frees the item; saving it bills it again.
        $invoice->update(['status' => InvoiceStatus::Cancelled]);
        $this->assertCount(0, app(BillableItemBiller::class)->run(CarbonImmutable::parse(self::NEXT_DAY)));
        $this->get("/admin/clients/{$this->client->id}?tab=billable-items")->assertSee('Cancelled; save the item to bill it again');
        $this->put("/admin/billable-items/{$item->id}", ['description' => self::LOGO, 'amount' => '75.00', 'invoice_action' => BillableItem::NEXT_CRON, 'due_date' => self::NEXT_DAY])
            ->assertSessionHasNoErrors();
        $this->assertNull($item->refresh()->invoice_id);
        $this->assertCount(1, app(BillableItemBiller::class)->run(CarbonImmutable::parse(self::NEXT_DAY)));

        $this->store(['amount' => '5']);
        $other = $this->client->billableItems()->latest('id')->first();
        $this->delete("/admin/billable-items/{$other->id}")->assertSessionHasNoErrors();
        $this->assertModelMissing($other);
    }

    public function test_changing_items_needs_the_billing_permission(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'support']));
        $item = $this->client->billableItems()->create(['description' => 'Setup', 'amount' => 100]);

        $this->get("/admin/clients/{$this->client->id}?tab=billable-items")->assertOk()->assertSee('Setup')->assertDontSee('Add billable item');
        $this->store(['amount' => '5'])->assertForbidden();
        $this->put("/admin/billable-items/{$item->id}", ['description' => 'X', 'amount' => '1', 'invoice_action' => BillableItem::NEXT_CRON])->assertForbidden();
        $this->delete("/admin/billable-items/{$item->id}")->assertForbidden();
    }

    public function test_merging_clients_moves_their_items(): void
    {
        $item = $this->client->billableItems()->create(['description' => 'Setup', 'amount' => 100]);
        $into = Client::factory()->create();

        app(ClientMerger::class)->merge($this->client, $into);

        $this->assertSame($into->id, $item->refresh()->client_id);
    }
}
