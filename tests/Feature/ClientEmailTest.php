<?php

namespace Tests\Feature;

use App\Billing\InvoiceReminders;
use App\Billing\OrderService;
use App\Billing\PaymentRecorder;
use App\Enums\BillingCycle;
use App\Events\InvoiceIssued;
use App\Mail\LoggedEmail;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ClientEmailTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPLATES = '/admin/email-templates';

    private const INVOICE_TEMPLATE = '/admin/email-templates/invoice_created';

    private function invoiceFor(Client $client, string $due = '2026-10-11'): Invoice
    {
        $invoice = Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => $due, 'due_date' => $due]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => 1250]);

        return $invoice->recalculate();
    }

    public function test_new_orders_email_the_invoice_with_the_pdf_attached(): void
    {
        Mail::fake();
        $client = Client::factory()->create(['first_name' => 'Jane']);
        $product = Product::factory()->create(['name' => 'Web hosting']);
        $product->prices()->create(['billing_cycle' => 'monthly', 'currency' => 'USD', 'price' => 1500]);

        $order = app(OrderService::class)->create($client, $product, BillingCycle::Monthly, today());
        $invoice = $order['invoice'];

        Mail::assertSent(LoggedEmail::class, function (LoggedEmail $mail) use ($client, $invoice) {
            $attachments = $mail->attachments();

            return $mail->hasTo($client->email)
                && $mail->hasSubject("Invoice #{$invoice->number} from ".config('billing.company.name'))
                && count($attachments) === 1
                && str_starts_with($attachments[0]->as, 'invoice-');
        });

        $log = EmailLog::sole();
        $this->assertSame(['invoice_created', EmailLog::SENT, $invoice->id], [$log->template, $log->status, $log->invoice_id]);
        $this->assertStringContainsString('Hello Jane', $log->body);
        $this->assertStringContainsString('$15.00', $log->body);
        $this->assertTrue($log->attach_invoice_pdf);
    }

    public function test_pdf_is_left_off_when_the_setting_is_off_and_drafts_send_nothing(): void
    {
        Mail::fake();
        config(['billing.attach_invoice_pdf' => false]);
        $client = Client::factory()->create();
        $invoice = $this->invoiceFor($client);

        InvoiceIssued::dispatch($invoice);
        Mail::assertSent(LoggedEmail::class, fn (LoggedEmail $mail) => $mail->attachments() === []);

        $invoice->update(['status' => 'draft']);
        InvoiceIssued::dispatch($invoice);
        $this->assertSame(1, EmailLog::count());
    }

    public function test_full_payment_sends_a_receipt(): void
    {
        Mail::fake();
        $invoice = $this->invoiceFor(Client::factory()->create());

        app(PaymentRecorder::class)->record($invoice, 1250, 'cash');

        $this->assertSame(['payment_receipt'], EmailLog::pluck('template')->all());
    }

    public function test_staff_edits_and_turn_off_templates(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->create());

        $this->get(self::TEMPLATES)->assertOk()->assertSee('Overdue reminder');
        $this->get(self::INVOICE_TEMPLATE)->assertOk()->assertSee('{invoice_number}');
        $this->get('/admin/email-templates/nope')->assertNotFound();

        $this->put(self::INVOICE_TEMPLATE, [
            'subject' => 'Bill {invoice_number} {unknown}',
            'body' => 'Hi {client_first_name}, <script>alert(1)</script> pay [here]({invoice_url}).',
            'enabled' => '1',
        ])->assertRedirect(self::TEMPLATES);

        $client = Client::factory()->create(['first_name' => 'Jo']);
        $invoice = $this->invoiceFor($client);
        InvoiceIssued::dispatch($invoice);

        $log = EmailLog::sole();
        $this->assertSame("Bill {$invoice->number} {unknown}", $log->subject);
        Mail::assertSent(LoggedEmail::class, function (LoggedEmail $mail) {
            $html = $mail->render();

            return str_contains($html, 'Hi Jo') && ! str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;');
        });

        // The log shows it, and turned off, nothing more is sent.
        $this->get("/admin/emails/{$log->id}")->assertOk()->assertSee('Hi Jo')->assertDontSee('<script>alert(1)</script>', false);
        EmailTemplate::find('invoice_created')->update(['enabled' => false]);
        InvoiceIssued::dispatch($invoice);
        $this->assertSame(1, EmailLog::count());

        $this->put(self::INVOICE_TEMPLATE, ['reset' => '1'])->assertRedirect(self::TEMPLATES);
        $this->assertNull(EmailTemplate::find('invoice_created'));
    }

    public function test_failed_emails_are_logged_and_can_be_sent_again(): void
    {
        $client = Client::factory()->create();
        config(['mail.default' => 'missing']);
        InvoiceIssued::dispatch($this->invoiceFor($client));

        $log = EmailLog::sole();
        $this->assertSame(EmailLog::FAILED, $log->status);
        $this->assertStringContainsString('Mailer [missing] is not defined', $log->error);

        config(['mail.default' => 'array']);
        $this->actingAs(User::factory()->create());
        $this->get('/admin/emails?status=failed')->assertOk()->assertSee($log->subject);
        $this->post("/admin/emails/{$log->id}/resend")->assertRedirect();

        $this->assertSame(EmailLog::SENT, $log->fresh()->status);
        $this->post("/admin/emails/{$log->id}/resend")->assertStatus(422);
    }

    public function test_password_emails_cannot_be_resent_from_the_log(): void
    {
        Mail::fake();
        $client = Client::factory()->create();
        $client->sendPasswordResetNotification('secret-token');
        $log = EmailLog::sole();
        $log->update(['status' => EmailLog::FAILED]);

        $this->actingAs(User::factory()->create());
        $this->post("/admin/emails/{$log->id}/resend")->assertStatus(422);
        $this->assertStringNotContainsString('secret-token', $log->body);
    }

    public function test_overdue_reminders_go_once_per_threshold(): void
    {
        Mail::fake();
        config(['billing.reminder_days' => [1, 7]]);
        $client = Client::factory()->create();
        $invoice = $this->invoiceFor($client, '2026-10-01');
        $old = $this->invoiceFor($client, '2026-06-01');
        $run = function (string $date) {
            $this->travelTo(CarbonImmutable::parse($date.' 00:05'));

            return app(InvoiceReminders::class)->run(today());
        };

        // The invoice already long overdue gets one reminder, not one per threshold.
        $this->assertSame(1, $run('2026-10-01'));
        $this->assertSame(1, $run('2026-10-02'));
        $this->assertSame(0, $run('2026-10-03'));
        // Day 7 was missed; the next run catches up with one reminder.
        $this->assertSame(1, $run('2026-10-09'));
        $this->assertSame(0, $run('2026-10-20'));

        $this->assertSame(2, EmailLog::where('template', 'invoice_overdue')->where('invoice_id', $invoice->id)->count());
        $this->assertSame(1, EmailLog::where('template', 'invoice_overdue')->where('invoice_id', $old->id)->count());
    }

    public function test_billing_staff_see_a_clients_emails_but_only_settings_staff_open_the_log(): void
    {
        Mail::fake();
        $client = Client::factory()->create();
        InvoiceIssued::dispatch($this->invoiceFor($client));
        $log = EmailLog::sole();

        $this->actingAs(User::factory()->create(['role' => 'billing']));
        $this->get("/admin/clients/{$client->id}")->assertOk()->assertSee($log->subject);
        $this->get('/admin/emails')->assertForbidden();
        $this->get("/admin/emails/{$log->id}")->assertForbidden();
    }
}
