<?php

namespace Tests\Feature\Api;

use App\Enums\ServiceStatus;
use App\Enums\TicketStatus;
use App\Events\ClientCreated;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Tickets\TicketDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ApiEndpointsTest extends TestCase
{
    use MakesApiKeys, RefreshDatabase;

    private const CLIENTS = '/api/v1/clients';

    private const TICKETS = '/api/v1/tickets';

    private const TICKET_SUBJECT = 'Site down';

    private const TICKET_BODY = 'Help please';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    private function invoice(Client $client, int $amount = 1000): Invoice
    {
        $invoice = Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => $amount]);

        return $invoice->recalculate();
    }

    public function test_clients_are_listed_searched_and_paginated(): void
    {
        $this->withApiKey(['clients:read']);
        Client::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
        Client::factory()->count(3)->create();
        Client::factory()->create(['first_name' => 'Old', 'status' => 'closed']);

        $this->getJson(self::CLIENTS.'?per_page=2')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonStructure(['data' => [['id', 'first_name', 'email', 'status', 'credit_balance', 'address1']], 'links' => ['next'], 'meta' => ['current_page', 'last_page']]);

        $this->getJson(self::CLIENTS.'?q=jane+doe')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.first_name', 'Jane');
        $this->getJson(self::CLIENTS.'?status=closed')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.first_name', 'Old');
        $this->getJson(self::CLIENTS.'?show_closed=1')->assertOk()->assertJsonPath('meta.total', 5);
        $this->getJson(self::CLIENTS.'?per_page=500')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_a_client_is_shown(): void
    {
        $this->withApiKey(['clients:read']);
        $client = Client::factory()->create(['city' => 'Springfield', 'credit_balance' => 250]);

        $this->getJson(self::CLIENTS."/{$client->id}")->assertOk()
            ->assertJsonPath('data.id', $client->id)
            ->assertJsonPath('data.city', 'Springfield')
            ->assertJsonPath('data.credit_balance', 250)
            ->assertJsonMissingPath('data.password');
        $this->getJson(self::CLIENTS.'/999')->assertNotFound();
    }

    public function test_clients_are_created_and_updated(): void
    {
        Event::fake([ClientCreated::class]);
        $this->withApiKey(['clients:write']);

        $this->postJson(self::CLIENTS, ['first_name' => 'Jane'])->assertUnprocessable()->assertJsonValidationErrors(['last_name', 'email']);

        $id = $this->postJson(self::CLIENTS, ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com', 'marketing_emails' => true])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.marketing_emails', true)
            ->json('data.id');
        $client = Client::findOrFail($id);
        Event::assertDispatched(ClientCreated::class, fn (ClientCreated $event) => $event->client->is($client));
        $this->assertSame('Created client', Activity::where('subject_id', $id)->where('actor_type', 'api')->value('description'));

        $this->postJson(self::CLIENTS, ['first_name' => 'Jo', 'last_name' => 'Doe', 'email' => 'jane@example.com'])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->patchJson(self::CLIENTS."/{$id}", ['city' => 'Springfield'])->assertOk()
            ->assertJsonPath('data.city', 'Springfield')
            ->assertJsonPath('data.first_name', 'Jane');
        $this->patchJson(self::CLIENTS."/{$id}", ['status' => 'nonsense'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('Springfield', $client->fresh()->city);
    }

    public function test_services_are_listed_shown_and_actioned(): void
    {
        $this->withApiKey(['services:read', 'services:write']);
        $service = Service::factory()->create();
        Service::factory()->create(['status' => ServiceStatus::Suspended]);
        Service::factory()->create(['client_id' => $service->client_id, 'status' => ServiceStatus::Pending]);

        $this->getJson("/api/v1/services?client_id={$service->client_id}")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/services?status=suspended')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/services?product_id={$service->product_id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/services/{$service->id}")->assertOk()
            ->assertJsonPath('data.recurring_amount', 1000)
            ->assertJsonPath('data.product_name', $service->product->name)
            ->assertJsonMissingPath('data.password');

        $this->postJson("/api/v1/services/{$service->id}/suspend")->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->postJson("/api/v1/services/{$service->id}/suspend")->assertUnprocessable()->assertJsonPath('message', "Can't suspend a suspended service.");
        $this->postJson("/api/v1/services/{$service->id}/unsuspend")->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson("/api/v1/services/{$service->id}/terminate")->assertOk()->assertJsonPath('data.status', 'terminated');
        $this->postJson("/api/v1/services/{$service->id}/delete")->assertNotFound();
    }

    public function test_invoices_are_listed_shown_and_paid(): void
    {
        $this->withApiKey(['invoices:read', 'invoices:write']);
        $client = Client::factory()->create();
        $invoice = $this->invoice($client, 1500);
        $this->invoice(Client::factory()->create());

        $this->getJson("/api/v1/invoices?client_id={$client->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.total', 1500);
        $this->getJson('/api/v1/invoices?status=paid')->assertOk()->assertJsonCount(0, 'data');

        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", ['amount' => '10.00', 'method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", ['amount' => 1000, 'method' => 'gold'])->assertUnprocessable()->assertJsonValidationErrors('method');

        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", ['amount' => 1000, 'method' => 'bank_transfer', 'reference' => 'TX-1'])->assertCreated()
            ->assertJsonPath('data.amount', 1000)
            ->assertJsonPath('data.reference', 'TX-1')
            ->assertJsonPath('invoice.balance', 500)
            ->assertJsonPath('invoice.status', 'unpaid');
        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", ['amount' => 500, 'method' => 'cash'])->assertCreated()->assertJsonPath('invoice.status', 'paid');
        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", ['amount' => 500, 'method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->getJson("/api/v1/invoices/{$invoice->id}")->assertOk()
            ->assertJsonPath('data.balance', 0)
            ->assertJsonPath('data.items.0.description', 'Hosting')
            ->assertJsonPath('data.items.0.amount', 1500)
            ->assertJsonCount(2, 'data.payments')
            ->assertJsonPath('data.payments.0.method', 'Bank transfer');
    }

    public function test_orders_are_listed_and_shown(): void
    {
        $this->withApiKey(['orders:read']);
        $client = Client::factory()->create();
        $invoice = $this->invoice($client);
        $order = Order::create(['client_id' => $client->id, 'invoice_id' => $invoice->id, 'status' => 'active']);
        $service = Service::factory()->create(['client_id' => $client->id, 'order_id' => $order->id]);
        Order::create(['client_id' => Client::factory()->create()->id, 'status' => 'pending']);

        $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/orders?client_id={$client->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.service_ids', [$service->id]);
        $this->getJson('/api/v1/orders?status=pending')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.services.0.id', $service->id)
            ->assertJsonPath('data.invoice.id', $invoice->id);
    }

    public function test_products_are_listed_and_shown_with_prices(): void
    {
        $this->withApiKey(['products:read']);
        $product = Product::factory()->create(['name' => 'Starter', 'module_config' => ['password' => 'secret']]);
        $product->prices()->create(['billing_cycle' => 'monthly', 'currency' => 'USD', 'price' => 999, 'setup_fee' => 500]);
        Product::factory()->create(['active' => false]);

        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('data.0.prices');
        $this->getJson('/api/v1/products?active=0')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/products/{$product->id}")->assertOk()
            ->assertJsonPath('data.name', 'Starter')
            ->assertJsonPath('data.prices.0.billing_cycle', 'monthly')
            ->assertJsonPath('data.prices.0.price', 999)
            ->assertJsonPath('data.prices.0.setup_fee', 500)
            ->assertJsonMissingPath('data.module_config');
    }

    public function test_tickets_are_listed_and_shown_without_internal_notes(): void
    {
        $this->withApiKey(['tickets:read']);
        $client = Client::factory()->create();
        $department = Department::create(['name' => 'Support', 'active' => true]);
        $ticket = app(TicketDesk::class)->open($client, $department, self::TICKET_SUBJECT, self::TICKET_BODY);
        app(TicketDesk::class)->staffReply($ticket, User::factory()->create(), 'Only staff see this', internal: true);
        app(TicketDesk::class)->open(Client::factory()->create(), $department, 'Billing question', 'Hi');

        $this->getJson(self::TICKETS)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson(self::TICKETS."?client_id={$client->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject', self::TICKET_SUBJECT);
        $this->getJson(self::TICKETS.'?status=closed')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::TICKETS."/{$ticket->id}")->assertOk()
            ->assertJsonPath('data.department', 'Support')
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.from', 'client')
            ->assertJsonPath('data.messages.0.body', self::TICKET_BODY)
            ->assertDontSee('Only staff see this');
    }

    public function test_tickets_are_opened_and_replied_to_as_staff_or_client(): void
    {
        $key = $this->withApiKey(['tickets:write']);
        $client = Client::factory()->create();
        $department = Department::create(['name' => 'Support', 'active' => true]);
        $closed = Department::create(['name' => 'Old', 'active' => false]);

        $this->postJson(self::TICKETS, ['client_id' => $client->id, 'department_id' => $closed->id, 'subject' => 'Hi', 'body' => 'Hello'])
            ->assertUnprocessable()->assertJsonValidationErrors('department_id');

        $id = $this->postJson(self::TICKETS, ['client_id' => $client->id, 'department_id' => $department->id, 'subject' => self::TICKET_SUBJECT, 'body' => self::TICKET_BODY, 'priority' => 'high'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.messages.0.body', self::TICKET_BODY)
            ->json('data.id');

        $this->postJson(self::TICKETS."/{$id}/replies", ['body' => 'Looking into it'])->assertCreated()
            ->assertJsonPath('data.from', 'staff')
            ->assertJsonPath('data.author', 'Key Maker')
            ->assertJsonPath('ticket.status', 'answered');
        $this->postJson(self::TICKETS."/{$id}/replies", ['body' => 'Thanks', 'as' => 'client'])->assertCreated()
            ->assertJsonPath('data.from', 'client')
            ->assertJsonPath('ticket.status', 'customer_reply');
        $this->postJson(self::TICKETS."/{$id}/replies", ['body' => 'x', 'as' => 'robot'])->assertUnprocessable()->assertJsonValidationErrors('as');

        $staffReply = TicketMessage::where('body', 'Looking into it')->sole();
        $this->assertSame($key->user_id, $staffReply->user_id);
        $this->assertSame(TicketStatus::CustomerReply, Ticket::find($id)->status);
        $this->assertSame('API key: Test key', Activity::where('description', "Replied to ticket #{$id}")->value('actor_name'));

        // Without the staff member who made it, a key can't reply as staff.
        $key->user->delete();
        $this->postJson(self::TICKETS."/{$id}/replies", ['body' => 'Hello?'])->assertUnprocessable()->assertJsonValidationErrors('as');
    }
}
