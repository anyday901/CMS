<?php

namespace Tests\Feature\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_full_flow_from_product_to_paid_invoice(): void
    {
        $this->post('/admin/products', [
            'name' => 'VPS Small',
            'active' => '1',
            'prices' => [
                'monthly' => ['price' => '10.00', 'setup_fee' => '5'],
                'annually' => ['price' => '$100', 'setup_fee' => ''],
            ],
        ])->assertRedirect('/admin/products');

        $product = Product::sole();
        $this->assertSame([1000, 10000], $product->prices()->orderBy('price')->pluck('price')->all());
        $this->get('/admin/products')->assertSee('$10.00 monthly');

        $this->post('/admin/clients', [
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com', 'status' => 'active',
        ])->assertRedirect();
        $client = Client::sole();
        $this->get('/admin/clients?q=jane')->assertSee('Jane Doe');

        $this->get("/admin/clients/{$client->id}/services/create")->assertOk()->assertSee('VPS Small');
        $response = $this->post("/admin/clients/{$client->id}/services", [
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'start_date' => today()->toDateString(),
            'label' => 'vps1.example.com',
            'invoice' => '1',
        ]);

        $service = Service::sole();
        $invoice = Invoice::sole();
        $response->assertRedirect("/admin/invoices/{$invoice->id}");
        $this->assertSame(ServiceStatus::Pending, $service->status);
        $this->assertSame(1500, $invoice->total);

        $this->get("/admin/invoices/{$invoice->id}")->assertOk()->assertSee('Setup fee')->assertSee('$15.00');

        $this->post("/admin/invoices/{$invoice->id}/payments", ['amount' => '15.00', 'method' => 'cashapp', 'reference' => 'CA-1'])
            ->assertSessionHasNoErrors();

        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->assertSame(today()->addMonthNoOverflow()->toDateString(), $service->next_due_date->toDateString());

        $this->get('/admin')->assertSee('$15.00');
        $this->get("/admin/services/{$service->id}")->assertOk()->assertSee('vps1.example.com');
        $this->get("/admin/clients/{$client->id}")->assertOk()->assertSee('VPS Small');
    }

    public function test_service_without_invoice_starts_active(): void
    {
        $product = Product::factory()->create();
        $product->prices()->create(['billing_cycle' => 'monthly', 'currency' => 'USD', 'price' => 500]);
        $client = Client::factory()->create();

        $this->post("/admin/clients/{$client->id}/services", [
            'product_id' => $product->id, 'billing_cycle' => 'monthly', 'start_date' => '2026-12-01', 'invoice' => '0',
        ]);

        $this->assertSame(ServiceStatus::Active, Service::sole()->status);
        $this->assertSame(0, Invoice::count());
    }

    public function test_missing_price_is_a_validation_error(): void
    {
        $product = Product::factory()->create();
        $client = Client::factory()->create();

        $this->post("/admin/clients/{$client->id}/services", [
            'product_id' => $product->id, 'billing_cycle' => 'annually', 'start_date' => '2026-12-01', 'invoice' => '1',
        ])->assertSessionHasErrors('billing_cycle');
    }

    public function test_service_actions(): void
    {
        $service = Service::factory()->create();

        $this->post("/admin/services/{$service->id}/unsuspend")->assertStatus(422);
        $this->post("/admin/services/{$service->id}/suspend")->assertRedirect();
        $this->assertSame(ServiceStatus::Suspended, $service->refresh()->status);
        $this->post("/admin/services/{$service->id}/unsuspend");
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->post("/admin/services/{$service->id}/terminate");
        $this->assertSame(ServiceStatus::Terminated, $service->refresh()->status);
        $this->post("/admin/services/{$service->id}/delete")->assertNotFound();
    }

    public function test_cancel_invoice_only_when_unpaid_and_untouched(): void
    {
        $client = Client::factory()->create();
        $invoice = Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $invoice->items()->create(['description' => 'Thing', 'amount' => 1000]);
        $invoice->recalculate();

        $this->post("/admin/invoices/{$invoice->id}/payments", ['amount' => '4', 'method' => 'cash']);
        $this->post("/admin/invoices/{$invoice->id}/cancel")->assertStatus(422);

        $other = Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $this->post("/admin/invoices/{$other->id}/cancel")->assertRedirect();
        $this->assertSame(InvoiceStatus::Cancelled, $other->refresh()->status);
    }

    public function test_cancelling_first_invoice_cancels_pending_service(): void
    {
        $product = Product::factory()->create();
        $product->prices()->create(['billing_cycle' => 'monthly', 'currency' => 'USD', 'price' => 500]);
        $client = Client::factory()->create();
        $this->post("/admin/clients/{$client->id}/services", [
            'product_id' => $product->id, 'billing_cycle' => 'monthly', 'start_date' => '2026-12-01', 'invoice' => '1',
        ]);

        $this->post('/admin/invoices/'.Invoice::sole()->id.'/cancel')->assertRedirect();

        $this->assertSame(ServiceStatus::Cancelled, Service::sole()->status);
    }

    public function test_reused_reference_shows_an_error(): void
    {
        $client = Client::factory()->create();
        [$a, $b] = collect([1, 2])->map(function () use ($client) {
            $invoice = Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
            $invoice->items()->create(['description' => 'Thing', 'amount' => 1000]);

            return $invoice->recalculate();
        });

        $this->post("/admin/invoices/{$a->id}/payments", ['amount' => '10', 'method' => 'paypal', 'reference' => 'TX1'])
            ->assertSessionHasNoErrors();
        $this->post("/admin/invoices/{$b->id}/payments", ['amount' => '10', 'method' => 'paypal', 'reference' => 'TX1'])
            ->assertSessionHasErrors('amount');
        $this->assertSame(InvoiceStatus::Unpaid, $b->refresh()->status);
    }

    public function test_malformed_price_is_rejected(): void
    {
        $this->post('/admin/products', ['name' => 'X', 'prices' => ['monthly' => ['price' => '12,34.56']]])
            ->assertSessionHasErrors('prices.monthly.price');
        $this->assertSame(0, Product::count());
    }

    public function test_invoice_list_filters(): void
    {
        $client = Client::factory()->create();
        Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => '2026-01-01', 'due_date' => '2026-01-01']);

        $this->get('/admin/invoices?status=overdue')->assertOk()->assertSee('Overdue');
        $this->get('/admin/invoices?status=paid')->assertOk()->assertSee('No invoices.');
    }

    public function test_bad_amount_is_rejected(): void
    {
        $client = Client::factory()->create();
        $invoice = Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);

        $this->post("/admin/invoices/{$invoice->id}/payments", ['amount' => 'abc', 'method' => 'cash'])
            ->assertSessionHasErrors('amount');
    }
}
