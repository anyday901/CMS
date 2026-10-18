<?php

namespace Tests\Feature\Billing;

use App\Billing\InvoiceCanceller;
use App\Billing\InvoiceNumbering;
use App\Billing\PaymentRecorder;
use App\Mail\ClientMailer;
use App\Models\Activity;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\NumberSequence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class PaidInvoiceNumberingTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/settings/invoice-numbering';

    /** The credit note fields on the same page, left as they are. */
    private const CREDIT_NOTES = ['credit_note_number_format' => 'CN-{NUMBER}', 'credit_note_next_number' => '1', 'shown_credit_note_next_number' => '1'];

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Client::factory()->create(['first_name' => 'Jane']);
    }

    private function sequential(string $format = 'INV-{YEAR}-{NUMBER}'): void
    {
        config(['billing.sequential_paid_numbers' => true, 'billing.invoice_number_format' => $format]);
    }

    private function invoice(int $amount = 1000): Invoice
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => $amount]);

        return $invoice->recalculate();
    }

    public function test_off_by_default_so_invoices_keep_their_id_as_the_number(): void
    {
        $invoice = $this->invoice();
        app(PaymentRecorder::class)->record($invoice, 1000, 'cash');

        $invoice->refresh();
        $this->assertFalse($invoice->proforma);
        $this->assertSame((string) $invoice->id, $invoice->number);
        $this->assertSame("Invoice #{$invoice->id}", $invoice->label());
        $this->assertSame(1, NumberSequence::peek(NumberSequence::INVOICE));
    }

    public function test_unpaid_invoices_are_proformas_numbered_in_order_when_paid(): void
    {
        $this->sequential();
        $first = $this->invoice();
        $unpaid = $this->invoice();
        $second = $this->invoice();

        $this->assertTrue($first->proforma);
        $this->assertSame((string) $first->id, $first->number);
        $this->assertSame("Proforma #{$first->id}", $first->label());

        $payments = app(PaymentRecorder::class);
        $payments->record($second, 1000, 'cash');
        $payments->record($first, 400, 'cash');
        $this->assertTrue($first->refresh()->proforma, 'A part payment leaves it a proforma.');
        $payments->record($first, 600, 'cash');

        $year = now()->format('Y');
        $this->assertSame("INV-{$year}-1", $second->refresh()->number);
        $this->assertSame("INV-{$year}-2", $first->refresh()->number);
        $this->assertSame("Invoice #INV-{$year}-2", $first->label());
        $this->assertTrue($unpaid->refresh()->proforma);
        $this->assertSame((string) $unpaid->id, $unpaid->number);
        $this->assertSame(3, NumberSequence::peek(NumberSequence::INVOICE));
        $this->assertTrue(Activity::where('description', "Numbered proforma #{$first->id} as invoice #INV-{$year}-2 on payment")->exists());
    }

    public function test_cancelled_proformas_and_failed_payments_leave_no_gaps(): void
    {
        $this->sequential('{YEAR}/{MONTH}/{NUMBER}');
        $cancelled = $this->invoice();
        app(InvoiceCanceller::class)->cancel($cancelled);

        // A payment rolled back after taking a number gives it back.
        $invoice = $this->invoice();
        $this->assertThrows(fn () => DB::transaction(function () use ($invoice) {
            app(PaymentRecorder::class)->record($invoice, 1000, 'cash');
            throw new InvalidArgumentException('Rolled back');
        }), InvalidArgumentException::class);
        $this->assertTrue($invoice->refresh()->proforma);

        app(PaymentRecorder::class)->record($invoice, 1000, 'cash');

        $this->assertSame(now()->format('Y/m').'/1', $invoice->refresh()->number);
        $this->assertTrue($cancelled->refresh()->proforma);
    }

    public function test_numbers_already_in_use_are_passed_over(): void
    {
        $this->sequential();
        $taken = $this->invoice();
        $taken->forceFill(['number' => 'INV-'.now()->format('Y').'-1', 'proforma' => false])->save();
        $invoice = $this->invoice();

        app(PaymentRecorder::class)->record($invoice, 1000, 'cash');

        $this->assertSame('INV-'.now()->format('Y').'-2', $invoice->refresh()->number);
    }

    public function test_invoices_made_before_it_was_turned_on_keep_their_numbers(): void
    {
        $before = $this->invoice();
        $this->sequential();

        app(PaymentRecorder::class)->record($before, 1000, 'cash');

        $this->assertSame((string) $before->id, $before->refresh()->number);
    }

    public function test_format_needs_the_number_and_a_letter_or_symbol(): void
    {
        $this->assertNull(InvoiceNumbering::problem('INV-{NUMBER}'));
        $this->assertNull(InvoiceNumbering::problem('{YEAR}-{NUMBER}'));
        $this->assertNotNull(InvoiceNumbering::problem('{NUMBER}'));
        $this->assertNotNull(InvoiceNumbering::problem('{YEAR}{NUMBER}'));
        $this->assertNotNull(InvoiceNumbering::problem('INV-{YEAR}'));
    }

    public function test_admins_turn_it_on_and_set_the_format_and_next_number(): void
    {
        $this->actingAs(User::factory()->create(), 'web');

        $this->get('/admin/settings')->assertOk()->assertSee('Invoice numbering');
        $this->get(self::URL)->assertOk()->assertSee('INV-'.now()->format('Y').'-1');

        $this->put(self::URL, self::CREDIT_NOTES + ['sequential_paid_numbers' => '1', 'invoice_number_format' => '{NUMBER}', 'next_number' => '5', 'shown_next_number' => '1'])
            ->assertSessionHasErrors('invoice_number_format');

        $this->put(self::URL, self::CREDIT_NOTES + ['sequential_paid_numbers' => '1', 'invoice_number_format' => 'F{YEAR}-{NUMBER}', 'next_number' => '1001', 'shown_next_number' => '1'])
            ->assertRedirect(self::URL);
        $this->assertTrue(config('billing.sequential_paid_numbers'));
        $this->assertSame(1001, NumberSequence::peek(NumberSequence::INVOICE));
        $this->assertTrue(Activity::where('description', 'Updated invoice numbering settings')->exists());

        // Saving again with the number as shown doesn't wind the sequence back.
        NumberSequence::take(NumberSequence::INVOICE);
        $this->put(self::URL, self::CREDIT_NOTES + ['sequential_paid_numbers' => '1', 'invoice_number_format' => 'F{YEAR}-{NUMBER}', 'next_number' => '1001', 'shown_next_number' => '1001']);
        $this->assertSame(1002, NumberSequence::peek(NumberSequence::INVOICE));

        $invoice = $this->invoice();
        app(PaymentRecorder::class)->record($invoice, 1000, 'cash');
        $this->assertSame('F'.now()->format('Y').'-1002', $invoice->refresh()->number);
    }

    public function test_proforma_label_shows_in_the_admin_area_portal_pdf_and_emails(): void
    {
        $this->sequential();
        $invoice = $this->invoice();

        $this->actingAs(User::factory()->create(), 'web');
        $this->get("/admin/invoices/{$invoice->id}")->assertOk()->assertSee("Proforma #{$invoice->id}");
        $this->assertStringContainsString("proforma-{$invoice->id}.pdf", $this->get("/admin/invoices/{$invoice->id}/pdf")->headers->get('Content-Disposition'));
        $html = view('pdf.invoice', ['invoice' => $invoice->load('items', 'transactions', 'client'), 'company' => config('billing.company')])->render();
        $this->assertStringContainsString("Proforma #{$invoice->id}", $html);

        $this->actingAs($this->client, 'client');
        $this->get('/portal/invoices')->assertOk()->assertSee('Proforma');
        $this->get("/portal/invoices/{$invoice->id}")->assertOk()->assertSee("Proforma #{$invoice->id}");

        app(ClientMailer::class)->send($this->client, 'invoice_created', invoice: $invoice);
        $this->assertSame("Invoice #{$invoice->id} (proforma) from ".config('billing.company.name'), EmailLog::latest('id')->first()->subject);

        // The receipt goes out once it's paid, with the new number.
        app(PaymentRecorder::class)->record($invoice, 1000, 'cash');
        $number = $invoice->refresh()->number;
        $this->assertSame("Receipt for invoice #{$number}", EmailLog::where('template', 'payment_receipt')->latest('id')->first()->subject);
        $this->get("/portal/invoices/{$invoice->id}")->assertOk()->assertSee("Invoice #{$number}");
    }
}
