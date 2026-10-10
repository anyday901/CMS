<?php

namespace Tests\Feature\Billing;

use App\Billing\CreditApplier;
use App\Billing\InvoiceGenerator;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceFor(Client $client, int $amount): Invoice
    {
        $invoice = Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => $amount]);

        return $invoice->recalculate();
    }

    public function test_renewal_invoices_use_credit_automatically(): void
    {
        $client = Client::factory()->create();
        $client->forceFill(['credit_balance' => 1500])->save();
        $service = Service::factory()->for($client)->create(['recurring_amount' => 1000, 'next_due_date' => '2026-11-01']);

        $invoice = app(InvoiceGenerator::class)->generate(CarbonImmutable::parse('2026-11-01'))->sole();

        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(500, $client->refresh()->credit_balance);
        $this->assertSame('credit', $invoice->transactions->sole()->gateway);
        $this->assertSame('2026-12-01', $service->refresh()->next_due_date->toDateString());
    }

    public function test_partial_credit_leaves_a_balance_and_can_be_turned_off(): void
    {
        config(['billing.apply_credit_automatically' => false]);
        $client = Client::factory()->create();
        $client->forceFill(['credit_balance' => 300])->save();
        Service::factory()->for($client)->create(['recurring_amount' => 1000, 'next_due_date' => '2026-11-01']);

        $invoice = app(InvoiceGenerator::class)->generate(CarbonImmutable::parse('2026-11-01'))->sole();
        $this->assertSame(1000, $invoice->balance());

        app(CreditApplier::class)->apply($invoice);

        $this->assertSame(InvoiceStatus::Unpaid, $invoice->refresh()->status);
        $this->assertSame(700, $invoice->balance());
        $this->assertSame(0, $client->refresh()->credit_balance);
        $this->assertNull(app(CreditApplier::class)->apply($invoice));
    }

    public function test_admin_and_client_can_apply_credit(): void
    {
        $client = Client::factory()->create(['password' => 'client-password']);
        $client->forceFill(['credit_balance' => 2000])->save();
        $first = $this->invoiceFor($client, 1500);
        $second = $this->invoiceFor($client, 1500);

        $this->actingAs(User::factory()->create(), 'web');
        $this->get("/admin/invoices/{$first->id}")->assertSee('Apply credit');
        $this->post("/admin/invoices/{$first->id}/credit", ['amount' => '10.00'])->assertSessionHas('status');
        $this->assertSame(500, $first->balance());
        $this->assertSame(1000, $client->refresh()->credit_balance);

        $this->actingAs($client, 'client');
        $this->get("/portal/invoices/{$second->id}")->assertSee('Use my $10.00 account credit');
        $this->post("/portal/invoices/{$second->id}/credit")->assertRedirect("/portal/invoices/{$second->id}");
        $this->assertSame(500, $second->balance());
        $this->assertSame(0, $client->refresh()->credit_balance);

        $someoneElse = $this->invoiceFor(Client::factory()->create(), 1000);
        $this->post("/portal/invoices/{$someoneElse->id}/credit")->assertNotFound();
    }

    public function test_credit_payments_are_not_counted_as_income(): void
    {
        $client = Client::factory()->create();
        $client->forceFill(['credit_balance' => 1000])->save();
        app(CreditApplier::class)->apply($this->invoiceFor($client, 1000));
        $this->assertSame(1, Transaction::count());

        $this->actingAs(User::factory()->create(), 'web');
        $this->get('/admin')->assertViewHas('incomeThisMonth', 0);
    }
}
