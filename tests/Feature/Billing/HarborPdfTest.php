<?php

namespace Tests\Feature\Billing;

use App\Billing\InvoicePdf;
use App\Billing\QuotePdf;
use App\Enums\InvoiceStatus;
use App\Enums\QuoteStage;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Invoice and quote PDFs share the Harbor colours, and still render as PDFs. */
class HarborPdfTest extends TestCase
{
    use RefreshDatabase;

    /** Harbor's brand-600 rule under the header, and its dark ink for text. */
    private const HARBOR = ['border-bottom: 3px solid #0e7c6b', 'color: #13322f'];

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Client::factory()->create();
    }

    public function test_invoice_pdfs_use_the_harbor_colours(): void
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'status' => InvoiceStatus::Paid, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => 1000]);
        $invoice->recalculate();

        $html = view('pdf.invoice', ['invoice' => $invoice->load('items', 'transactions', 'client'), 'company' => config('billing.company')])->render();

        foreach (self::HARBOR as $css) {
            $this->assertStringContainsString($css, $html);
        }
        // Paid and unpaid badges keep a border, so they still show printed in greyscale.
        $this->assertStringContainsString('.status-paid { background: #d7f0ea; color: #0d5048; border-color: #0d5048; }', $html);
        $this->assertStringContainsString('class="status status-paid"', $html);
        $this->assertStringNotContainsString('#111827', $html);
        $this->assertStringStartsWith('%PDF', app(InvoicePdf::class)->output($invoice));
    }

    public function test_quote_pdfs_use_the_harbor_colours(): void
    {
        $quote = Quote::create(['client_id' => $this->client->id, 'subject' => 'Migration', 'stage' => QuoteStage::Delivered, 'currency' => 'USD', 'issue_date' => today(), 'valid_until' => today()->addDays(30)]);
        $quote->items()->create(['description' => 'Migration', 'amount' => 20000]);
        $quote->recalculate();

        $html = view('pdf.quote', ['quote' => $quote->load('client', 'items'), 'company' => config('billing.company')])->render();

        foreach (self::HARBOR as $css) {
            $this->assertStringContainsString($css, $html);
        }
        $this->assertStringStartsWith('%PDF', app(QuotePdf::class)->output($quote));
    }
}
