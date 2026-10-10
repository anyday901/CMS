<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceFor(Client $client, InvoiceStatus $status = InvoiceStatus::Unpaid): Invoice
    {
        $invoice = Invoice::create(['client_id' => $client->id, 'status' => $status, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => 1000]);

        return $invoice->recalculate();
    }

    public function test_staff_download_a_pdf(): void
    {
        $invoice = $this->invoiceFor(Client::factory()->create());
        $this->actingAs(User::factory()->create(), 'web');

        $response = $this->get("/admin/invoices/{$invoice->id}/pdf")->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString("invoice-{$invoice->number}.pdf", $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_pdf_view_shows_company_and_invoice_details(): void
    {
        config(['billing.company' => ['name' => 'Acme Hosting', 'address' => "1 Main St\nTown", 'email' => 'billing@acme.test', 'tax_id' => 'EIN 12-3456789']]);
        $client = Client::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
        $invoice = $this->invoiceFor($client);

        $html = view('pdf.invoice', ['invoice' => $invoice->load('items', 'transactions', 'client'), 'company' => config('billing.company')])->render();

        foreach (['Acme Hosting', '1 Main St', 'billing@acme.test', 'EIN 12-3456789', 'Jane Doe', 'Hosting', '$10.00'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    public function test_clients_download_only_their_own_published_invoices(): void
    {
        $client = Client::factory()->create();
        $mine = $this->invoiceFor($client);
        $draft = $this->invoiceFor($client, InvoiceStatus::Draft);
        $theirs = $this->invoiceFor(Client::factory()->create());

        $this->actingAs($client, 'client');

        $this->get("/portal/invoices/{$mine->id}")->assertSee('Download PDF');
        $this->get("/portal/invoices/{$mine->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get("/portal/invoices/{$draft->id}/pdf")->assertNotFound();
        $this->get("/portal/invoices/{$theirs->id}/pdf")->assertNotFound();
    }
}
