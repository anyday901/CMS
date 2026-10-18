<?php

namespace Tests\Feature\Portal;

use App\Billing\AccountStatement;
use App\Billing\CreditApplier;
use App\Billing\CreditLedger;
use App\Billing\PaymentRecorder;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountStatementTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 12:00:00');
        $this->client = Client::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
    }

    private function invoice(string $date, int $total, string $status = 'unpaid', ?Client $client = null): Invoice
    {
        return Invoice::forceCreate([
            'client_id' => ($client ?? $this->client)->id, 'currency' => 'USD', 'issue_date' => $date, 'due_date' => $date,
            'status' => $status, 'subtotal' => $total, 'total' => $total,
        ]);
    }

    /** Invoices, a payment, a refund and credit, before and inside the range. */
    private function history(): void
    {
        $this->invoice('2025-12-01', 400);
        $this->travelTo('2026-02-01 09:00:00');
        $paid = $this->invoice('2026-02-01', 1000);
        $payment = app(PaymentRecorder::class)->record($paid, 1000, 'paypal', 'PP-1');
        $this->travelTo('2026-03-01 09:00:00');
        Transaction::create(['client_id' => $this->client->id, 'invoice_id' => $paid->id, 'refund_of_id' => $payment->id, 'gateway' => 'paypal', 'currency' => 'USD', 'amount' => -300, 'gateway_reference' => 'PP-R1']);
        $this->travelTo('2026-04-01 09:00:00');
        app(CreditLedger::class)->adjust($this->client, 500, 'Goodwill credit');
        $this->travelTo('2026-05-01 09:00:00');
        $credited = $this->invoice('2026-05-01', 200);
        app(CreditApplier::class)->apply($credited);
        $this->invoice('2026-06-01', 9999, 'cancelled');
        $this->invoice('2026-06-01', 8888, 'draft');
        $this->invoice('2026-06-01', 7777, 'unpaid', Client::factory()->create());
        $this->travelTo('2026-10-15 12:00:00');
    }

    public function test_clients_see_their_statement_for_the_dates_they_pick(): void
    {
        $this->history();
        $this->actingAs($this->client, 'client');

        $this->get('/portal/invoices')->assertSee('Account statement');

        $this->get('/portal/statement?from=2026-01-01&to=2026-10-15')->assertOk()
            ->assertSee('Opening balance on Jan 1, 2026')
            ->assertSeeInOrder(['$4.00', 'Charged', '$15.00', 'Paid', '$12.00', 'Closing balance on Oct 15, 2026', '$7.00'])
            ->assertSeeInOrder(['Invoice', '$10.00', '$14.00', 'Payment', 'PayPal PP-1', '$4.00', 'Refund', '$3.00', '$7.00', 'Invoice', '$9.00', 'Credit applied', 'From your credit balance', '$7.00'])
            ->assertSeeInOrder(['Credit balance', '$0.00 on Jan 1, 2026, $3.00 at the end of Oct 15, 2026.', 'Goodwill credit', '$5.00', 'Paid invoice', '-$2.00', '$3.00'])
            ->assertDontSee('$99.99')
            ->assertDontSee('$88.88')
            ->assertDontSee('$77.77');

        // Before the range, everything is brought forward.
        $this->get('/portal/statement?from=2026-04-15&to=2026-10-15')->assertOk()
            ->assertSee('Opening balance on Apr 15, 2026')
            ->assertSeeInOrder(['Opening balance', '$7.00'])
            ->assertSee('$5.00 on Apr 15, 2026, $3.00 at the end of Oct 15, 2026.');
    }

    public function test_an_invoice_issued_on_the_last_day_is_in_the_statement(): void
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'status' => 'unpaid', 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()->addDays(7)]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => 1234]);
        $invoice->recalculate();

        $statement = AccountStatement::for($this->client, today()->toImmutable()->subMonth(), today()->toImmutable());

        $this->assertSame(['invoice'], array_column($statement->lines, 'type'));
        $this->assertSame('2026-10-15', $statement->lines[0]['date']);
        $this->assertSame(1234, $statement->closing);
    }

    public function test_the_statement_defaults_to_this_year_and_ignores_dates_that_do_not_parse(): void
    {
        $this->actingAs($this->client, 'client');

        $this->get('/portal/statement')->assertOk()->assertSee('value="2026-01-01"', false)->assertSee('value="2026-10-15"', false)
            ->assertSee('No invoices or payments in these dates.');
        $this->get('/portal/statement?from=2026-02-30&to=nonsense')->assertOk()->assertSee('value="2026-01-01"', false);
        // Dates the wrong way round are swapped.
        $this->get('/portal/statement?from=2026-06-30&to=2026-03-01')->assertOk()->assertSee('value="2026-03-01"', false)->assertSee('value="2026-06-30"', false);
    }

    public function test_clients_download_their_statement_as_a_pdf(): void
    {
        $this->history();
        $this->actingAs($this->client, 'client');

        $response = $this->get('/portal/statement/pdf?from=2026-01-01&to=2026-10-15')->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('statement-2026-01-01-to-2026-10-15.pdf', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_pdf_view_shows_the_statement_in_harbor_colours(): void
    {
        $this->history();
        config(['billing.company' => ['name' => 'Acme Hosting', 'address' => '1 Main St', 'email' => 'billing@acme.test', 'tax_id' => null]]);
        $statement = AccountStatement::for($this->client, now()->toImmutable()->setDate(2026, 1, 1), now()->toImmutable());

        $html = view('pdf.statement', ['statement' => $statement, 'company' => config('billing.company')])->render();

        foreach (['Acme Hosting', 'Account statement', 'Ada Lovelace', 'Opening balance', '$4.00', 'PayPal PP-1', 'Goodwill credit', 'Closing balance on Oct 15, 2026', '#0e7c6b'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    public function test_contacts_need_the_billing_permission(): void
    {
        $contact = $this->client->contacts()->create(['first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'pat@example.com', 'portal_access' => true, 'permissions' => ['support']]);
        $contact->forceFill(['password' => 'contact-password-1'])->save();
        $this->post('/portal/login', ['email' => 'pat@example.com', 'password' => 'contact-password-1']);

        $this->get('/portal/statement')->assertRedirect('/portal');
        $this->get('/portal/statement/pdf')->assertRedirect('/portal');

        $contact->update(['permissions' => ['billing']]);
        $this->get('/portal/statement')->assertOk();
    }

    public function test_guests_are_sent_to_log_in(): void
    {
        $this->get('/portal/statement')->assertRedirect('/portal/login');
    }

    public function test_the_admin_report_shows_the_same_statement_with_the_credit_balance(): void
    {
        $this->history();
        $this->actingAs(User::factory()->create(), 'web');

        $this->get('/admin/reports/client-statement?from=2026-01-01&to=2026-10-15&client='.$this->client->id)->assertOk()
            ->assertSee('Balance brought forward from before Jan 1, 2026: $4.00')
            ->assertSeeInOrder(['Refund', 'Paypal PP-R1', '$3.00', '$7.00'])
            ->assertSeeInOrder(['Credit balance', 'Goodwill credit', '$5.00', '$5.00', 'Paid invoice', '-$2.00', '$3.00'])
            ->assertSee('Credit balance before Jan 1, 2026: $0.00; at the end of Oct 15, 2026: $3.00.');

        $csv = $this->get('/admin/reports/client-statement/export?from=2026-01-01&to=2026-10-15&client='.$this->client->id)->assertOk()->streamedContent();
        $this->assertStringContainsString('"Goodwill credit",5.00,5.00', $csv);
    }
}
