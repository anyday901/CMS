<?php

namespace Tests\Feature\Billing;

use App\Billing\AddFunds;
use App\Billing\CreditNoteIssuer;
use App\Billing\InvoiceCanceller;
use App\Billing\PaymentRecorder;
use App\Billing\RefundReconciler;
use App\Clients\ClientMerger;
use App\Enums\InvoiceStatus;
use App\Enums\StaffRole;
use App\Models\Activity;
use App\Models\Client;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\NumberSequence;
use App\Models\TaxRule;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class CreditNoteTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Outage on Oct 3';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        TaxRule::create(['name' => 'Sales tax', 'rate' => 1000, 'country' => 'US']);
        $this->client = Client::factory()->create(['first_name' => 'Jane', 'country' => 'US']);
    }

    /** Hosting $20.00 taxed at 10% and setup $5.00 untaxed: $27.00. */
    private function invoice(): Invoice
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => 2000, 'taxable' => true]);
        $invoice->items()->create(['description' => 'Setup', 'amount' => 500, 'taxable' => false]);

        return $invoice->recalculate();
    }

    private function paid(string $gateway = 'cash', ?string $reference = null): Invoice
    {
        $invoice = $this->invoice();
        app(PaymentRecorder::class)->record($invoice, $invoice->total, $gateway, $reference);

        return $invoice->refresh();
    }

    /** @return array<int, int> every line in full */
    private function allLines(Invoice $invoice): array
    {
        return $invoice->items->mapWithKeys(fn ($item) => [$item->id => $item->amount])->all();
    }

    private function line(Invoice $invoice, string $description): int
    {
        return $invoice->items()->where('description', $description)->value('id');
    }

    public function test_crediting_a_whole_paid_invoice_to_account_credit(): void
    {
        $invoice = $this->paid();
        $this->assertSame(2700, $invoice->total);

        $note = app(CreditNoteIssuer::class)->issue($invoice, $this->allLines($invoice), self::REASON, 'credit');

        $this->assertSame('CN-1', $note->number);
        $this->assertSame([2500, 200, 2700], [$note->subtotal, $note->tax, $note->total]);
        $this->assertSame(2700, $this->client->refresh()->credit_balance);
        $this->assertSame(-2700, $note->refunds()->sum('amount'));
        $this->assertSame(InvoiceStatus::Refunded, $invoice->refresh()->status);
        $this->assertSame(0, $invoice->balance());
        $this->assertSame(2700, $invoice->credited());
        $this->assertTrue(Activity::where('description', 'Issued credit note CN-1 for $27.00 on invoice #'.$invoice->number.': '.self::REASON)->exists());
        $this->assertSame(2, NumberSequence::peek(NumberSequence::CREDIT_NOTE));

        $this->expectException(InvalidArgumentException::class);
        app(CreditNoteIssuer::class)->issue($invoice, [$this->line($invoice, 'Setup') => 100], self::REASON, 'credit');
    }

    public function test_crediting_part_of_a_line_refunds_one_payment_with_its_tax(): void
    {
        $invoice = $this->paid();
        $payment = $invoice->transactions()->first();
        $issuer = app(CreditNoteIssuer::class);
        $hosting = $this->line($invoice, 'Hosting');

        $note = $issuer->issue($invoice, [$hosting => 1000], self::REASON, 'manual', $payment);

        $this->assertSame([1000, 100, 1100], [$note->subtotal, $note->tax, $note->total]);
        $this->assertSame(1600, $payment->refundable());
        $this->assertSame(0, $this->client->refresh()->credit_balance);
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertSame(0, $invoice->balance());

        // Only what's left of the line can be credited again.
        try {
            $issuer->issue($invoice, [$hosting => 1001], self::REASON, 'credit');
            $this->fail('Credited more than was left on the line.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('$10.00 left to credit', $e->getMessage());
        }

        $second = $issuer->issue($invoice, [$hosting => 1000], 'The rest', 'credit');
        $this->assertSame('CN-2', $second->number);
        $this->assertSame(1100, $this->client->refresh()->credit_balance);
        $this->assertSame(0, CreditNoteIssuer::creditableLines($invoice)[$hosting]);
    }

    public function test_tax_inclusive_invoices_credit_the_tax_inside_the_amount(): void
    {
        config(['billing.tax_inclusive' => true]);
        $invoice = $this->paid();

        $note = app(CreditNoteIssuer::class)->issue($invoice, [$this->line($invoice, 'Hosting') => 1100], self::REASON, 'credit');

        $this->assertSame([1100, 100, 1100], [$note->subtotal, $note->tax, $note->total]);
        $this->assertTrue($note->tax_inclusive);
    }

    public function test_part_paid_invoices_can_be_credited_up_to_what_was_paid(): void
    {
        $invoice = $this->invoice();
        app(PaymentRecorder::class)->record($invoice, 1000, 'cash');
        $issuer = app(CreditNoteIssuer::class);

        try {
            $issuer->issue($invoice, [$this->line($invoice, 'Hosting') => 2000], self::REASON, 'credit');
            $this->fail('Credited more than was paid.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('more than is left to credit', $e->getMessage());
        }

        $issuer->issue($invoice, [$this->line($invoice, 'Setup') => 500], self::REASON, 'credit');

        // What's still owed doesn't change: the credit note takes it off the total and its refund off what's paid.
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame(500, $invoice->amountPaid());
        $this->assertSame(1700, $invoice->balance());
        $this->assertFalse($invoice->isEditable());
    }

    public function test_only_paid_or_part_paid_invoices_can_be_credited(): void
    {
        $unpaid = $this->invoice();
        $cancelled = $this->invoice();
        app(InvoiceCanceller::class)->cancel($cancelled);

        $this->assertFalse(CreditNoteIssuer::canCredit($unpaid));
        $this->assertFalse(CreditNoteIssuer::canCredit($cancelled));

        $this->expectException(InvalidArgumentException::class);
        app(CreditNoteIssuer::class)->issue($unpaid, $this->allLines($unpaid), self::REASON, 'credit');
    }

    public function test_a_refund_the_gateway_turns_down_leaves_no_credit_note_or_gap(): void
    {
        config(['payments.paypal' => ['mode' => 'sandbox', 'client_id' => 'id', 'secret' => 'secret', 'webhook_id' => null, 'venmo' => true]]);
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
            'api-m.sandbox.paypal.com/v2/payments/captures/CAP1/refund' => Http::sequence()
                ->push(['name' => 'UNPROCESSABLE_ENTITY', 'message' => 'Refund refused'], 422)
                ->push(['id' => 'REF1', 'status' => 'COMPLETED'], 201),
        ]);
        $invoice = $this->paid('paypal', 'CAP1');
        $payment = $invoice->transactions()->first();
        $issuer = app(CreditNoteIssuer::class);

        $this->assertThrows(
            fn () => $issuer->issue($invoice, $this->allLines($invoice), self::REASON, 'gateway', $payment),
            InvalidArgumentException::class,
        );
        $this->assertSame(0, CreditNote::count());

        $note = $issuer->issue($invoice, $this->allLines($invoice), self::REASON, 'gateway', $payment);

        $this->assertSame('CN-1', $note->number);
        $this->assertSame('REF1', $note->refunds()->first()->gateway_reference);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/captures/CAP1/refund') && $request['amount']['value'] === '27.00');
    }

    public function test_staff_issue_a_credit_note_and_see_it_on_the_invoice_and_client_pages(): void
    {
        $invoice = $this->paid();
        $payment = $invoice->transactions()->first();
        $this->actingAs(User::factory()->create(['role' => StaffRole::Admin]), 'web');

        $this->get("/admin/invoices/{$invoice->id}")->assertOk()->assertSee('Issue credit note');
        $this->get("/admin/invoices/{$invoice->id}/credit-notes/create")->assertOk()->assertSee('Hosting')->assertSee('Already refunded outside the app: Cash payment of $27.00', false);

        $this->post("/admin/invoices/{$invoice->id}/credit-notes", ['lines' => [], 'reason' => self::REASON, 'refund' => 'credit'])
            ->assertSessionHasErrors('lines');
        $this->post("/admin/invoices/{$invoice->id}/credit-notes", ['lines' => [$this->line($invoice, 'Setup') => '5.00'], 'reason' => self::REASON, 'refund' => "manual:{$payment->id}"])
            ->assertRedirect("/admin/invoices/{$invoice->id}")
            ->assertSessionHas('status', 'Credit note CN-1 issued for $5.00.');

        $note = CreditNote::sole();
        $this->assertSame('manual', $note->refund_mode);
        $this->get("/admin/invoices/{$invoice->id}")->assertOk()->assertSee('CN-1')->assertSee(self::REASON)->assertSee('Credited')->assertSee('credit note CN-1');
        $this->get("/admin/clients/{$this->client->id}?tab=invoices")->assertOk()->assertSee('Credit notes')->assertSee('CN-1');
        $this->get("/admin/invoices/{$invoice->id}/edit")->assertRedirect("/admin/invoices/{$invoice->id}");

        $response = $this->get("/admin/credit-notes/{$note->id}/pdf")->assertOk();
        $this->assertStringContainsString('credit-note-CN-1.pdf', $response->headers->get('Content-Disposition'));
        $html = view('pdf.credit-note', ['note' => $note->load('items', 'invoice', 'client', 'refunds.refundOf'), 'company' => config('billing.company')])->render();
        foreach (['Credit note CN-1', 'Against invoice #'.$invoice->number, 'Setup', '$5.00', self::REASON, 'Refunded to your Cash payment'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    public function test_support_staff_cannot_issue_credit_notes(): void
    {
        $invoice = $this->paid();
        $this->actingAs(User::factory()->create(['role' => StaffRole::Support]), 'web');

        $this->get("/admin/invoices/{$invoice->id}")->assertOk()->assertDontSee('Issue credit note');
        $this->get("/admin/invoices/{$invoice->id}/credit-notes/create")->assertForbidden();
        $this->post("/admin/invoices/{$invoice->id}/credit-notes", ['lines' => $this->allLines($invoice), 'reason' => self::REASON, 'refund' => 'credit'])->assertForbidden();
        $this->assertSame(0, CreditNote::count());
    }

    public function test_clients_see_and_download_their_own_credit_notes(): void
    {
        $invoice = $this->paid();
        $note = app(CreditNoteIssuer::class)->issue($invoice, $this->allLines($invoice), self::REASON, 'credit');
        $other = Client::factory()->create();
        $otherInvoice = Invoice::create(['client_id' => $other->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $otherInvoice->items()->create(['description' => 'Hosting', 'amount' => 1000]);
        $otherInvoice->recalculate();
        app(PaymentRecorder::class)->record($otherInvoice, 1000, 'cash');
        $theirs = app(CreditNoteIssuer::class)->issue($otherInvoice, $this->allLines($otherInvoice->refresh()), 'Theirs', 'credit');

        $this->actingAs($this->client, 'client');

        $this->get('/portal/invoices')->assertOk()->assertSee('Credit notes')->assertSee('CN-1')->assertDontSee('Theirs');
        $this->get("/portal/invoices/{$invoice->id}")->assertOk()->assertSee('CN-1')->assertSee(self::REASON)->assertSee('To account credit');
        $this->get("/portal/credit-notes/{$note->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get("/portal/credit-notes/{$theirs->id}/pdf")->assertNotFound();

        app()->setLocale('de');
        $this->assertSame('Gutschriften', __('Credit notes'));
    }

    public function test_credit_note_numbers_follow_the_format_and_next_number_setting(): void
    {
        $this->actingAs(User::factory()->create(['role' => StaffRole::Admin]), 'web');
        $this->put('/admin/settings/invoice-numbering', [
            'sequential_paid_numbers' => '0', 'invoice_number_format' => 'INV-{NUMBER}', 'next_number' => '1', 'shown_next_number' => '1',
            'credit_note_number_format' => 'CR{YEAR}-{NUMBER}', 'credit_note_next_number' => '50', 'shown_credit_note_next_number' => '1',
        ])->assertRedirect('/admin/settings/invoice-numbering');

        $invoice = $this->paid();
        $note = app(CreditNoteIssuer::class)->issue($invoice, $this->allLines($invoice), self::REASON, 'credit');

        $this->assertSame('CR'.now()->format('Y').'-50', $note->number);
    }

    public function test_merging_clients_moves_their_credit_notes(): void
    {
        $invoice = $this->paid();
        $note = app(CreditNoteIssuer::class)->issue($invoice, $this->allLines($invoice), self::REASON, 'credit');
        $into = Client::factory()->create(['currency' => $this->client->currency]);

        app(ClientMerger::class)->merge($this->client, $into);

        $this->assertSame($into->id, $note->refresh()->client_id);
        $this->assertInstanceOf(Transaction::class, $note->refunds()->first());
    }

    public function test_add_funds_invoices_cannot_be_credited(): void
    {
        config(['billing.add_funds.enabled' => true]);
        $invoice = app(AddFunds::class)->create($this->client, 5000);
        app(PaymentRecorder::class)->record($invoice, 5000, 'cash');

        $this->expectException(InvalidArgumentException::class);
        app(CreditNoteIssuer::class)->issue($invoice->refresh(), $this->allLines($invoice), self::REASON, CreditNote::MODES[0]);
    }

    public function test_a_credit_note_whose_pending_refund_fails_is_voided(): void
    {
        config(['payments.paypal' => ['mode' => 'sandbox', 'client_id' => 'id', 'secret' => 'secret', 'webhook_id' => null, 'venmo' => true]]);
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
            'api-m.sandbox.paypal.com/v2/payments/captures/CAP1/refund' => Http::response(['id' => 'REF1', 'status' => 'PENDING'], 201),
            'api-m.sandbox.paypal.com/v2/payments/refunds/REF1' => Http::response(['id' => 'REF1', 'status' => 'FAILED']),
        ]);
        $invoice = $this->paid('paypal', 'CAP1');
        $note = app(CreditNoteIssuer::class)->issue($invoice, $this->allLines($invoice), self::REASON, 'gateway', $invoice->transactions()->first());
        $this->assertSame([], array_filter(CreditNoteIssuer::creditableLines($invoice)));

        app(RefundReconciler::class)->run();

        // The client got nothing back, so the credit note no longer counts and a new one can be issued.
        $this->assertTrue($note->refresh()->isVoided());
        $this->assertSame(0, $invoice->refresh()->creditNotes()->count());
        $this->assertSame($this->allLines($invoice), CreditNoteIssuer::creditableLines($invoice));
        $this->assertTrue(Activity::where('description', 'Voided credit note CN-1 because its gateway refund failed')->exists());
    }
}
