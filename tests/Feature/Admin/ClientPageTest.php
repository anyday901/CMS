<?php

namespace Tests\Feature\Admin;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Tickets\TicketDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The client page: totals across the top, and a side menu showing one section at a time. */
class ClientPageTest extends TestCase
{
    use RefreshDatabase;

    private const PAST = '2026-01-01';

    private const HIDDEN_NOTES = 'id="section-notes" data-section="notes" hidden';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->client = Client::factory()->create();
    }

    public function test_the_tiles_add_up_what_the_client_owes_and_has_open(): void
    {
        $overdue = $this->invoice(2500, self::PAST);
        $overdue->transactions()->create(['client_id' => $this->client->id, 'gateway' => 'manual', 'currency' => 'USD', 'amount' => 1000]);
        $this->invoice(1500, today()->addWeek()->toDateString());
        $this->invoice(9900, self::PAST, InvoiceStatus::Paid);
        Service::factory()->for($this->client)->create();
        app(TicketDesk::class)->open($this->client, Department::create(['name' => 'Support']), 'Site is down', 'Help');

        $this->get("/admin/clients/{$this->client->id}")
            ->assertOk()
            ->assertSeeInOrder(['Owed now', '$30.00', '2 unpaid invoices, 1 overdue'])
            ->assertSeeInOrder(['Active services', '1'])
            ->assertSeeInOrder(['Open tickets', '1', '1 awaiting your reply']);
    }

    public function test_the_tab_in_the_address_picks_the_section_shown(): void
    {
        $page = "/admin/clients/{$this->client->id}";

        $this->get($page)->assertSee('id="section-summary" data-section="summary">', false)->assertSee(self::HIDDEN_NOTES, false);
        $this->get("{$page}?tab=notes")->assertSee('id="section-notes" data-section="notes">', false)->assertSee('id="section-summary" data-section="summary" hidden', false);
        $this->get("{$page}?tab=nonsense")->assertSee('id="section-summary" data-section="summary">', false)->assertSee(self::HIDDEN_NOTES, false);
    }

    public function test_domains_stay_out_of_the_menu_until_domains_are_sold(): void
    {
        $this->get("/admin/clients/{$this->client->id}")->assertDontSee('data-section-link="domains"', false);
        $this->get("/admin/clients/{$this->client->id}?tab=domains")->assertSee('id="section-summary" data-section="summary">', false);
    }

    private function invoice(int $amount, string $due, InvoiceStatus $status = InvoiceStatus::Unpaid): Invoice
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'status' => $status, 'currency' => 'USD', 'issue_date' => self::PAST, 'due_date' => $due]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => $amount]);

        return $invoice->recalculate();
    }
}
