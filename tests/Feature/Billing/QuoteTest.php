<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\QuoteStage;
use App\Enums\StaffRole;
use App\Mail\LoggedEmail;
use App\Models\Activity;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\TaxRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class QuoteTest extends TestCase
{
    use RefreshDatabase;

    private const SUBJECT = 'Website redesign';

    private const LINE = 'Move 3 sites';

    private const PORTAL_QUOTES_URL = '/portal/quotes';

    private const ACCEPT_BUTTON = 'Accept quote';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        TaxRule::create(['name' => 'Sales tax', 'rate' => 825, 'country' => 'US']);
        $this->client = Client::factory()->create(['first_name' => 'Jane', 'country' => 'US']);
    }

    private function staff(StaffRole $role = StaffRole::Admin): static
    {
        return $this->actingAs(User::factory()->create(['role' => $role]), 'web');
    }

    private function form(array $extra = []): array
    {
        return $extra + [
            'subject' => self::SUBJECT,
            'stage' => 'draft',
            'issue_date' => today()->toDateString(),
            'valid_until' => today()->addDays(30)->toDateString(),
            'notes' => 'Five pages, two rounds of changes.',
            'admin_notes' => 'Quoted low to win the work.',
            'items' => [
                ['description' => 'Design', 'amount' => '1,000.00', 'taxable' => '1'],
                ['description' => '', 'amount' => ''], // blank rows are ignored
                ['description' => 'Hosting setup', 'amount' => '50', 'taxable' => '0'],
            ],
        ];
    }

    private function quote(QuoteStage $stage = QuoteStage::Delivered, array $attributes = []): Quote
    {
        $quote = Quote::create($attributes + [
            'client_id' => $this->client->id,
            'subject' => 'Migration',
            'stage' => $stage,
            'currency' => 'USD',
            'issue_date' => today(),
            'valid_until' => today()->addDays(30),
        ]);
        $quote->items()->create(['description' => self::LINE, 'amount' => 20000]);
        $quote->items()->create(['description' => 'Discount', 'amount' => -2000]);

        return $quote->recalculate();
    }

    public function test_staff_create_a_quote_with_tax_worked_out_like_an_invoice(): void
    {
        $this->staff();
        $this->get("/admin/clients/{$this->client->id}/quotes/create")->assertOk()->assertSee('Add line');

        $this->post("/admin/clients/{$this->client->id}/quotes", $this->form())->assertRedirect();

        $quote = Quote::sole();
        $this->assertSame(QuoteStage::Draft, $quote->stage);
        $this->assertSame(['Design', 'Hosting setup'], $quote->items()->orderBy('id')->pluck('description')->all());
        $this->assertSame(105000, $quote->subtotal);
        $this->assertSame(8250, $quote->tax); // 8.25% of the taxable 1,000.00 only
        $this->assertSame(113250, $quote->total);
        $this->assertSame('Sales tax (8.25%)', $quote->taxLabel());
        $this->assertNull($quote->delivered_at);
        $this->assertTrue(Activity::where('subject_type', $quote->getMorphClass())->where('subject_id', $quote->id)->where('description', 'like', 'Created quote%')->exists());

        $this->get("/admin/quotes/{$quote->id}")->assertOk()->assertSee(self::SUBJECT)->assertSee('$1,132.50')->assertSee('Quoted low to win the work.');
        $this->get('/admin/quotes')->assertOk()->assertSee(self::SUBJECT);
        $this->get('/admin/quotes?stage=accepted')->assertOk()->assertDontSee(self::SUBJECT);
        $this->get('/admin/quotes?q=redesign')->assertOk()->assertSee(self::SUBJECT);
        $this->get("/admin/clients/{$this->client->id}?tab=quotes")->assertOk()->assertSee(self::SUBJECT);
        $this->get('/admin/search?q=redesign')->assertOk()->assertSee("/admin/quotes/{$quote->id}");
    }

    public function test_tax_inclusive_pricing_and_validation(): void
    {
        config(['billing.tax_inclusive' => true]);
        $this->staff();

        $this->post("/admin/clients/{$this->client->id}/quotes", $this->form(['items' => [['description' => 'Design', 'amount' => '108.25', 'taxable' => '1']]]));
        $quote = Quote::sole();
        $this->assertTrue($quote->tax_inclusive);
        $this->assertSame(10825, $quote->total);
        $this->assertSame(825, $quote->tax);

        $this->post("/admin/clients/{$this->client->id}/quotes", $this->form(['items' => [['description' => 'Refund', 'amount' => '-10']]]))->assertSessionHasErrors('items');
        $this->post("/admin/clients/{$this->client->id}/quotes", $this->form(['valid_until' => today()->subDay()->toDateString()]))->assertSessionHasErrors('valid_until');
        $this->post("/admin/clients/{$this->client->id}/quotes", $this->form(['subject' => '']))->assertSessionHasErrors('subject');
        $this->assertSame(1, Quote::count());
    }

    public function test_editing_replaces_lines_and_sets_the_stage(): void
    {
        $this->staff();
        $quote = $this->quote(QuoteStage::Draft);
        $keep = $quote->items()->orderBy('id')->first();

        $this->get("/admin/quotes/{$quote->id}/edit")->assertOk()->assertSee(self::LINE);
        $this->put("/admin/quotes/{$quote->id}", $this->form([
            'stage' => 'on_hold',
            'items' => [['id' => $keep->id, 'description' => 'Move 4 sites', 'amount' => '300', 'taxable' => '0']],
        ]))->assertRedirect("/admin/quotes/{$quote->id}");

        $quote->refresh();
        $this->assertSame(QuoteStage::OnHold, $quote->stage);
        $this->assertSame(['Move 4 sites'], $quote->items()->pluck('description')->all());
        $this->assertSame(30000, $quote->total);
        $this->assertNotNull($quote->delivered_at, 'An on hold quote is visible to the client.');
    }

    public function test_duplicate_makes_a_new_draft_and_delete_removes_it(): void
    {
        $this->staff();
        $quote = $this->quote(QuoteStage::Lost);

        $this->post("/admin/quotes/{$quote->id}/duplicate")->assertRedirect();
        $copy = Quote::latest('id')->first();
        $this->assertNotSame($quote->id, $copy->id);
        $this->assertSame(QuoteStage::Draft, $copy->stage);
        $this->assertSame($quote->total, $copy->total);
        $this->assertSame(2, $copy->items()->count());
        $this->assertSame(30, (int) $copy->issue_date->diffInDays($copy->valid_until));

        $this->delete("/admin/quotes/{$copy->id}")->assertRedirect("/admin/clients/{$this->client->id}?tab=quotes");
        $this->assertNull(Quote::find($copy->id));
        $this->assertSame(1, Quote::count());
    }

    public function test_send_emails_the_quote_with_its_pdf_and_marks_it_delivered(): void
    {
        Mail::fake();
        $this->staff();
        $quote = $this->quote(QuoteStage::Draft);

        $this->post("/admin/quotes/{$quote->id}/send")->assertRedirect()->assertSessionHas('status');

        $this->assertSame(QuoteStage::Delivered, $quote->fresh()->stage);
        $this->assertNotNull($quote->fresh()->delivered_at);
        Mail::assertSent(LoggedEmail::class, function (LoggedEmail $mail) use ($quote) {
            $attachments = $mail->attachments();

            return $mail->hasTo($this->client->email)
                && $mail->hasSubject("Quote #{$quote->number} from ".config('billing.company.name').': Migration')
                && count($attachments) === 1
                && $attachments[0]->as === "quote-{$quote->number}.pdf";
        });

        $log = EmailLog::sole();
        $this->assertSame(['quote_delivered', EmailLog::SENT, $quote->id], [$log->template, $log->status, $log->quote_id]);
        $this->assertStringContainsString('Hello Jane', $log->body);
        $this->assertStringContainsString('$194.85', $log->body); // 180.00 plus 8.25% tax
        $this->assertStringContainsString("/portal/quotes/{$quote->id}", $log->body);
    }

    public function test_staff_convert_a_quote_to_an_unpaid_invoice_with_the_same_lines_and_tax(): void
    {
        Mail::fake();
        $this->staff();
        $quote = $this->quote();
        // A tax change after the quote was made doesn't change what the client was quoted.
        TaxRule::query()->update(['rate' => 1000]);

        $this->post("/admin/quotes/{$quote->id}/convert")->assertRedirect();

        $invoice = Invoice::sole();
        $quote->refresh();
        $this->assertSame($invoice->id, $quote->invoice_id);
        $this->assertSame(QuoteStage::Accepted, $quote->stage);
        $this->assertNotNull($quote->accepted_at);
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame([self::LINE, 'Discount'], $invoice->items()->orderBy('id')->pluck('description')->all());
        $this->assertSame([$quote->subtotal, $quote->tax, $quote->total, 825], [$invoice->subtotal, $invoice->tax, $invoice->total, $invoice->tax_rate]);
        $this->assertSame(19485, $invoice->total);
        $this->assertSame('invoice_created', EmailLog::sole()->template);

        // It can only be converted once, and can't be edited after.
        $this->post("/admin/quotes/{$quote->id}/convert")->assertSessionHasErrors('quote');
        $this->get("/admin/quotes/{$quote->id}/edit")->assertRedirect("/admin/quotes/{$quote->id}");
        $this->assertSame(1, Invoice::count());
        $this->get("/admin/quotes/{$quote->id}")->assertOk()->assertSee("invoice #{$invoice->number}");
    }

    public function test_staff_without_billing_permission_can_look_but_not_change(): void
    {
        $quote = $this->quote();
        $this->staff(StaffRole::Support);

        $this->get('/admin/quotes')->assertOk();
        $this->get("/admin/quotes/{$quote->id}")->assertOk()->assertDontSee('Convert to invoice');
        $this->get("/admin/clients/{$this->client->id}/quotes/create")->assertForbidden();
        $this->post("/admin/clients/{$this->client->id}/quotes", $this->form())->assertForbidden();
        $this->put("/admin/quotes/{$quote->id}", $this->form())->assertForbidden();
        $this->post("/admin/quotes/{$quote->id}/send")->assertForbidden();
        $this->post("/admin/quotes/{$quote->id}/convert")->assertForbidden();
        $this->post("/admin/quotes/{$quote->id}/duplicate")->assertForbidden();
        $this->delete("/admin/quotes/{$quote->id}")->assertForbidden();
        $this->assertSame(0, Invoice::count());
        $this->assertSame(1, Quote::count());
    }

    public function test_pdfs_render_for_staff_and_client(): void
    {
        config(['billing.company' => ['name' => 'Acme Hosting', 'address' => null, 'email' => null, 'tax_id' => null]]);
        $quote = $this->quote();

        $html = view('pdf.quote', ['quote' => $quote->load(['client', 'items']), 'company' => config('billing.company')])->render();
        $this->assertStringContainsString('Quote #'.$quote->number, $html);
        $this->assertStringContainsString(self::LINE, $html);
        $this->assertStringContainsString('Sales tax (8.25%)', $html);

        $this->staff();
        $response = $this->get("/admin/quotes/{$quote->id}/pdf")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString("quote-{$quote->number}.pdf", $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $this->actingAs($this->client, 'client');
        $this->assertStringStartsWith('%PDF', $this->get("/portal/quotes/{$quote->id}/pdf")->assertOk()->getContent());
    }

    public function test_clients_see_sent_quotes_but_never_drafts_or_other_clients_quotes(): void
    {
        $sent = $this->quote(QuoteStage::Delivered, ['subject' => 'Sent quote']);
        $draft = $this->quote(QuoteStage::Draft, ['subject' => 'Draft quote']);
        $theirs = $this->quote(QuoteStage::Delivered, ['subject' => 'Their quote', 'client_id' => Client::factory()->create()->id]);

        $this->actingAs($this->client, 'client');
        $this->get('/portal')->assertSee(self::PORTAL_QUOTES_URL);
        $this->get(self::PORTAL_QUOTES_URL)->assertOk()->assertSee('Sent quote')->assertDontSee('Draft quote')->assertDontSee('Their quote');
        $this->get("/portal/quotes/{$sent->id}")->assertOk()->assertSee(self::LINE)->assertSee('$194.85')->assertSee(self::ACCEPT_BUTTON);
        foreach ([$draft, $theirs] as $hidden) {
            $this->get("/portal/quotes/{$hidden->id}")->assertNotFound();
            $this->get("/portal/quotes/{$hidden->id}/pdf")->assertNotFound();
            $this->post("/portal/quotes/{$hidden->id}/accept")->assertNotFound();
        }
    }

    public function test_client_accepts_a_quote_and_is_sent_to_pay_the_invoice(): void
    {
        Mail::fake();
        $quote = $this->quote();
        $this->actingAs($this->client, 'client');

        $response = $this->post("/portal/quotes/{$quote->id}/accept");

        $invoice = Invoice::sole();
        $response->assertRedirect("/portal/invoices/{$invoice->id}");
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame(19485, $invoice->total);
        $quote->refresh();
        $this->assertSame(QuoteStage::Accepted, $quote->stage);
        $this->assertSame($invoice->id, $quote->invoice_id);
        $this->assertTrue(Activity::where('actor_type', 'client')->where('description', "Client accepted quote #{$quote->number}")->exists());

        $this->get("/portal/quotes/{$quote->id}")->assertOk()->assertSee("invoice #{$invoice->number}")->assertDontSee(self::ACCEPT_BUTTON);
        $this->post("/portal/quotes/{$quote->id}/accept")->assertSessionHasErrors('quote');
        $this->assertSame(1, Invoice::count());
    }

    public function test_terms_must_be_ticked_when_a_terms_url_is_set(): void
    {
        config(['store.terms_url' => 'https://example.test/terms']);
        $quote = $this->quote();
        $this->actingAs($this->client, 'client');

        $this->get("/portal/quotes/{$quote->id}")->assertSee('https://example.test/terms')->assertSee('accept_terms');
        $this->post("/portal/quotes/{$quote->id}/accept")->assertSessionHasErrors('accept_terms');
        $this->assertSame(0, Invoice::count());

        $this->post("/portal/quotes/{$quote->id}/accept", ['accept_terms' => '1'])->assertRedirect();
        $this->assertSame(1, Invoice::count());
    }

    public function test_client_declines_a_quote(): void
    {
        $quote = $this->quote(QuoteStage::OnHold);
        $this->actingAs($this->client, 'client');

        $this->post("/portal/quotes/{$quote->id}/decline")->assertRedirect(self::PORTAL_QUOTES_URL);

        $this->assertSame(QuoteStage::Lost, $quote->fresh()->stage);
        $this->assertSame(0, Invoice::count());
        $this->get("/portal/quotes/{$quote->id}")->assertNotFound();
        $this->assertTrue(Activity::where('description', "Client declined quote #{$quote->number}")->exists());
    }

    public function test_expired_quotes_cannot_be_accepted_or_declined(): void
    {
        $quote = $this->quote(QuoteStage::Delivered, ['issue_date' => today()->subDays(40), 'valid_until' => today()->subDay()]);
        $this->actingAs($this->client, 'client');

        $this->get(self::PORTAL_QUOTES_URL)->assertSee('Expired');
        $this->get("/portal/quotes/{$quote->id}")->assertOk()->assertSee('This quote expired on')->assertDontSee(self::ACCEPT_BUTTON);
        $this->post("/portal/quotes/{$quote->id}/accept")->assertSessionHasErrors('quote');
        $this->post("/portal/quotes/{$quote->id}/decline")->assertSessionHasErrors('quote');

        $this->assertSame(QuoteStage::Delivered, $quote->fresh()->stage);
        $this->assertSame(0, Invoice::count());
    }

    public function test_contacts_need_the_billing_permission(): void
    {
        $quote = $this->quote();
        $support = $this->client->contacts()->create(['first_name' => 'Sam', 'last_name' => 'Lee', 'email' => 'sam@example.test', 'portal_access' => true, 'permissions' => ['support']]);
        $billing = $this->client->contacts()->create(['first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'pat@example.test', 'portal_access' => true, 'permissions' => ['billing']]);

        $this->actingAs($this->client, 'client')->withSession(['portal_contact_id' => $support->id]);
        $this->get(self::PORTAL_QUOTES_URL)->assertRedirect()->assertSessionHasErrors();
        $this->post("/portal/quotes/{$quote->id}/accept")->assertRedirect();
        $this->assertSame(0, Invoice::count());

        $this->withSession(['portal_contact_id' => $billing->id]);
        $this->get("/portal/quotes/{$quote->id}")->assertOk()->assertSee(self::ACCEPT_BUTTON);
    }
}
