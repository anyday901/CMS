<?php

namespace Tests\Feature\Admin;

use App\Billing\PaymentRecorder;
use App\Enums\InvoiceStatus;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(), 'web');
        $this->client = Client::factory()->create();
    }

    private function form(array $items, array $extra = []): array
    {
        return $extra + [
            'issue_date' => today()->toDateString(),
            'due_date' => today()->addDays(7)->toDateString(),
            'notes' => 'Thanks!',
            'items' => $items,
        ];
    }

    public function test_staff_create_an_unpaid_invoice_with_custom_lines(): void
    {
        $this->get("/admin/clients/{$this->client->id}/invoices/create")->assertOk()->assertSee('Add line');

        $this->post("/admin/clients/{$this->client->id}/invoices", $this->form([
            ['description' => 'Migration work', 'amount' => '75.00', 'taxable' => '1'],
            ['description' => '', 'amount' => ''], // blank rows are ignored
            ['description' => 'Loyalty discount', 'amount' => '-5', 'taxable' => '0'],
        ], ['save' => 'unpaid']))->assertRedirect();

        $invoice = Invoice::sole();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame(7000, $invoice->total);
        $this->assertSame(['Migration work', 'Loyalty discount'], $invoice->items()->orderBy('id')->pluck('description')->all());
        $this->assertSame('Thanks!', $invoice->notes);
        $this->assertTrue(Activity::where('subject_id', $invoice->id)->where('description', 'like', 'Created invoice%')->exists());
    }

    public function test_line_needs_a_description_and_total_must_be_positive(): void
    {
        $this->post("/admin/clients/{$this->client->id}/invoices", $this->form([['description' => '', 'amount' => '10']]))
            ->assertSessionHasErrors('items.0.description');
        $this->post("/admin/clients/{$this->client->id}/invoices", $this->form([['description' => 'Refund', 'amount' => '-10']]))
            ->assertSessionHasErrors('items');
        $this->post("/admin/clients/{$this->client->id}/invoices", $this->form([]))
            ->assertSessionHasErrors('items');
        $this->assertSame(0, Invoice::count());
    }

    public function test_drafts_are_hidden_from_the_client_until_published(): void
    {
        $this->post("/admin/clients/{$this->client->id}/invoices", $this->form([['description' => 'Consulting', 'amount' => '50']], ['save' => 'draft']));
        $invoice = Invoice::sole();
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);

        $this->get("/admin/invoices/{$invoice->id}")->assertSee('This is a draft')->assertSee('Publish');
        $this->get('/admin/invoices?status=draft')->assertSee("/admin/invoices/{$invoice->id}");
        $this->get('/admin/invoices?status=unpaid')->assertDontSee("/admin/invoices/{$invoice->id}\"", false);

        $this->actingAs($this->client, 'client');
        $this->get('/portal/invoices')->assertDontSee('#'.$invoice->number);
        $this->get("/portal/invoices/{$invoice->id}")->assertNotFound();
        $this->get("/portal/invoices/{$invoice->id}/pdf")->assertNotFound();

        $this->actingAs(User::factory()->create(), 'web');
        $this->post("/admin/invoices/{$invoice->id}/publish")->assertRedirect();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);

        $this->actingAs($this->client, 'client');
        $this->get("/portal/invoices/{$invoice->id}")->assertOk();
    }

    public function test_publishing_applies_credit_like_any_new_invoice(): void
    {
        config(['billing.apply_credit_automatically' => true]);
        $this->client->forceFill(['credit_balance' => 2000])->save();

        $this->post("/admin/clients/{$this->client->id}/invoices", $this->form([['description' => 'Consulting', 'amount' => '50']], ['save' => 'draft']));
        $invoice = Invoice::sole();
        $this->assertSame(2000, $this->client->fresh()->credit_balance);

        $this->post("/admin/invoices/{$invoice->id}/publish");
        $this->assertSame(0, $this->client->fresh()->credit_balance);
        $this->assertSame(3000, $invoice->fresh()->balance());
    }

    public function test_drafts_can_be_cancelled(): void
    {
        $this->post("/admin/clients/{$this->client->id}/invoices", $this->form([['description' => 'Consulting', 'amount' => '50']], ['save' => 'draft']));
        $invoice = Invoice::sole();

        $this->post("/admin/invoices/{$invoice->id}/cancel")->assertRedirect();
        $this->assertSame(InvoiceStatus::Cancelled, $invoice->fresh()->status);
    }

    public function test_editing_changes_kept_lines_adds_new_ones_and_drops_removed_ones(): void
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $keep = $invoice->items()->create(['description' => 'Hosting', 'amount' => 1000]);
        $drop = $invoice->items()->create(['description' => 'Old line', 'amount' => 500]);
        $invoice->recalculate();

        $this->get("/admin/invoices/{$invoice->id}/edit")->assertOk()->assertSee('Old line');
        $this->put("/admin/invoices/{$invoice->id}", $this->form([
            ['id' => $keep->id, 'description' => 'Hosting (corrected)', 'amount' => '12.00', 'taxable' => '1'],
            ['description' => 'Extra IP', 'amount' => '3'],
        ]))->assertRedirect("/admin/invoices/{$invoice->id}");

        $invoice->refresh();
        $this->assertSame(1500, $invoice->total);
        $this->assertModelMissing($drop);
        $this->assertSame('Hosting (corrected)', $keep->fresh()->description);
        $this->assertTrue(Activity::where('description', 'like', "Edited invoice #{$invoice->number}%")->exists());
    }

    public function test_edits_cannot_drop_the_total_to_what_was_already_paid(): void
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $line = $invoice->items()->create(['description' => 'Hosting', 'amount' => 2000]);
        $invoice->recalculate();
        app(PaymentRecorder::class)->record($invoice, 1000, 'cash');

        $this->put("/admin/invoices/{$invoice->id}", $this->form([['id' => $line->id, 'description' => 'Hosting', 'amount' => '10.00']]))
            ->assertSessionHasErrors('items');
        $this->assertSame(2000, $invoice->fresh()->total);

        $this->put("/admin/invoices/{$invoice->id}", $this->form([['id' => $line->id, 'description' => 'Hosting', 'amount' => '15.00']]))
            ->assertSessionDoesntHaveErrors();
        $this->assertSame(500, $invoice->fresh()->balance());
    }

    public function test_paid_invoices_cannot_be_edited(): void
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $line = $invoice->items()->create(['description' => 'Hosting', 'amount' => 1000]);
        $invoice->recalculate();
        app(PaymentRecorder::class)->record($invoice, 1000, 'cash');

        $this->get("/admin/invoices/{$invoice->id}")->assertDontSee('>Edit<', false);
        $this->get("/admin/invoices/{$invoice->id}/edit")->assertRedirect("/admin/invoices/{$invoice->id}");
        $this->put("/admin/invoices/{$invoice->id}", $this->form([['id' => $line->id, 'description' => 'Hosting', 'amount' => '20']]))
            ->assertSessionHasErrors('items');
    }
}
