<?php

namespace Tests\Feature\Reports;

use App\Billing\CreditApplier;
use App\Enums\ServiceStatus;
use App\Enums\StaffRole;
use App\Models\CancellationRequest;
use App\Models\Client;
use App\Models\CreditEntry;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketFeedback;
use App\Models\TicketMessage;
use App\Models\Transaction;
use App\Models\User;
use App\Reports\ReportRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    private const PAID_AT = '2026-09-10 09:00:00';

    private const LATER_AT = '2026-09-20 10:00:00';

    private const TWENTY = '$20.00';

    use RefreshDatabase;

    private Client $client;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 12:00:00');
        $this->actingAs(User::factory()->create(), 'web');

        $this->client = Client::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'country' => 'DE', 'created_at' => '2026-03-02 10:00:00']);
        $this->product = Product::factory()->create(['name' => 'VPS Small']);
    }

    private function invoice(array $attributes): Invoice
    {
        return Invoice::forceCreate($attributes + ['subtotal' => $attributes['total'] ?? 0]);
    }

    /** A paid invoice for a service, with its payment on the day it was paid. */
    private function paidInvoice(Service $service, int $amount, string $paidAt, int $tax = 0, int $rate = 0): Invoice
    {
        $invoice = $this->invoice([
            'client_id' => $service->client_id, 'currency' => 'USD', 'issue_date' => substr($paidAt, 0, 10), 'due_date' => substr($paidAt, 0, 10),
            'status' => 'paid', 'subtotal' => $amount, 'tax' => $tax, 'tax_rate' => $rate, 'tax_name' => $rate ? 'VAT' : null, 'total' => $amount + $tax, 'paid_at' => $paidAt,
        ]);
        $invoice->items()->create(['service_id' => $service->id, 'type' => 'service', 'description' => 'Renewal', 'amount' => $amount]);
        $payment = Transaction::create(['client_id' => $service->client_id, 'invoice_id' => $invoice->id, 'gateway' => 'paypal', 'currency' => 'USD', 'amount' => $amount + $tax, 'fee' => 50]);
        $payment->forceFill(['created_at' => $paidAt])->save();

        return $invoice;
    }

    public function test_every_report_opens_and_exports_with_and_without_data(): void
    {
        foreach (app(ReportRegistry::class)->all() as $key => $report) {
            $this->get("/admin/reports/{$key}")->assertOk()->assertSee($report->title());
        }

        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $this->product->id]);
        $this->paidInvoice($service, 1000, self::PAID_AT);
        $department = Department::create(['name' => 'Support']);
        $ticket = Ticket::create(['client_id' => $this->client->id, 'department_id' => $department->id, 'subject' => 'Help', 'status' => 'closed', 'priority' => 'medium', 'closed_at' => now()]);
        TicketMessage::create(['ticket_id' => $ticket->id, 'client_id' => $this->client->id, 'body' => 'Hi']);
        TicketFeedback::create(['ticket_id' => $ticket->id, 'client_id' => $this->client->id, 'rating' => 4, 'comment' => '=Great']);
        DB::table('ticket_tags')->insert(['id' => 1, 'name' => 'billing', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ticket_ticket_tag')->insert(['ticket_id' => $ticket->id, 'ticket_tag_id' => 1]);

        foreach (app(ReportRegistry::class)->all() as $key => $report) {
            $this->get("/admin/reports/{$key}?client={$this->client->id}")->assertOk();
            $csv = $this->get("/admin/reports/{$key}/export?client={$this->client->id}")->assertOk()->streamedContent();
            $this->assertNotSame('', $csv, $key);
        }

        $this->get('/admin/reports')->assertOk()->assertSee('Income forecast')->assertSee('Client statement');
    }

    public function test_reports_need_the_view_reports_permission(): void
    {
        $support = User::factory()->create(['role' => StaffRole::Support]);
        $this->actingAs($support, 'web');
        $this->get('/admin/reports')->assertForbidden();
        $this->get('/admin/reports/annual-income/export')->assertForbidden();
        $this->get('/admin')->assertDontSee('Reports');

        $custom = User::factory()->create(['role' => StaffRole::Custom, 'permissions' => ['view-reports']]);
        $this->actingAs($custom, 'web');
        $this->get('/admin/reports/annual-income')->assertOk();

        $this->actingAs(User::factory()->create(['role' => StaffRole::Billing]), 'web');
        $this->get('/admin')->assertSee('Reports');
        $this->get('/admin/reports/monthly-transactions')->assertOk();
    }

    public function test_income_reports_count_payments_less_refunds_but_not_credit(): void
    {
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $this->product->id]);
        $invoice = $this->paidInvoice($service, 2000, self::PAID_AT);
        $this->paidInvoice($service, 2000, '2025-09-10 09:00:00');
        Transaction::create(['client_id' => $this->client->id, 'invoice_id' => $invoice->id, 'gateway' => 'paypal', 'currency' => 'USD', 'amount' => -500])->forceFill(['created_at' => self::LATER_AT])->save();
        Transaction::create(['client_id' => $this->client->id, 'invoice_id' => $invoice->id, 'gateway' => CreditApplier::GATEWAY, 'currency' => 'USD', 'amount' => 700])->forceFill(['created_at' => self::LATER_AT])->save();
        Transaction::create(['client_id' => $this->client->id, 'gateway' => 'paypal', 'currency' => 'EUR', 'amount' => 9900])->forceFill(['created_at' => self::LATER_AT])->save();

        $this->get('/admin/reports/annual-income?from=2025-01-01&to=2026-12-31')->assertOk()
            ->assertSeeInOrder(['2025', self::TWENTY, '2026', '$15.00', 'Total', '$35.00']);

        $csv = $this->get('/admin/reports/monthly-transactions/export?from=2026-09-01&to=2026-09-30')->streamedContent();
        $this->assertStringContainsString("Month,Payments,\"Amount in\",Fees,Refunds,Balance\n2026-09,1,20.00,0.50,5.00,14.50", $csv);

        $this->get('/admin/reports/monthly-transactions?from=2026-09-01&to=2026-09-30&currency=EUR')->assertOk()->assertSee('€99.00');

        $this->get('/admin/reports/top-clients?from=2026-01-01&to=2026-10-15')->assertOk()->assertSeeInOrder(['Ada Lovelace', self::TWENTY, '$5.00', '$15.00']);
        $this->get('/admin/reports/income-by-product?from=2026-01-01&to=2026-10-15')->assertOk()->assertSeeInOrder(['VPS Small', self::TWENTY]);
    }

    public function test_the_forecast_steps_each_active_service_through_its_renewals(): void
    {
        Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $this->product->id, 'recurring_amount' => 1000, 'next_due_date' => '2026-11-01']);
        Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $this->product->id, 'billing_cycle' => 'quarterly', 'recurring_amount' => 3000, 'recurring_discount' => 300, 'next_due_date' => '2026-10-20']);
        $cancelling = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $this->product->id, 'recurring_amount' => 99900]);
        CancellationRequest::create(['service_id' => $cancelling->id, 'type' => CancellationRequest::END_OF_PERIOD, 'cancel_on' => '2026-11-01']);
        Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $this->product->id, 'status' => ServiceStatus::Suspended, 'recurring_amount' => 88800]);

        $csv = $this->get('/admin/reports/income-forecast/export?from=2026-10-01&to=2027-01-31')->streamedContent();

        $this->assertStringContainsString("2026-10,1,27.00\n2026-11,1,10.00\n2026-12,1,10.00\n2027-01,2,37.00\n,5,84.00", $csv);
        $this->assertStringContainsString("Monthly,1,10.00,10.00\nQuarterly,1,27.00,9.00", $csv);
    }

    public function test_aging_buckets_what_is_left_to_pay_by_days_overdue(): void
    {
        foreach (['2026-10-20' => 1000, '2026-10-01' => 2000, '2026-08-01' => 3000, '2026-06-01' => 4000] as $due => $total) {
            $invoice = $this->invoice(['client_id' => $this->client->id, 'currency' => 'USD', 'issue_date' => $due, 'due_date' => $due, 'status' => 'unpaid', 'total' => $total, 'subtotal' => $total]);
        }
        // Part paid: only the rest is owed.
        Transaction::create(['client_id' => $this->client->id, 'invoice_id' => $invoice->id, 'gateway' => 'paypal', 'currency' => 'USD', 'amount' => 1500]);

        $csv = $this->get('/admin/reports/invoice-aging/export')->streamedContent();

        $this->assertStringContainsString('"Ada Lovelace",4,10.00,20.00,0.00,30.00,25.00,85.00', $csv);
    }

    public function test_the_client_statement_runs_a_balance_from_what_was_owed_before(): void
    {
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $this->product->id]);
        $this->invoice(['client_id' => $this->client->id, 'currency' => 'USD', 'issue_date' => '2025-12-01', 'due_date' => '2025-12-01', 'status' => 'unpaid', 'total' => 400]);
        $this->paidInvoice($service, 1000, '2026-02-01 09:00:00');
        $this->invoice(['client_id' => $this->client->id, 'currency' => 'USD', 'issue_date' => '2026-03-01', 'due_date' => '2026-03-01', 'status' => 'cancelled', 'total' => 9999]);

        $this->get('/admin/reports/client-statement?from=2026-01-01&to=2026-10-15&client='.$this->client->email)->assertOk()
            ->assertSee('Ada Lovelace')
            ->assertSee('Balance brought forward from before Jan 1, 2026: $4.00')
            ->assertSeeInOrder(['Invoice', '$10.00', '$14.00', 'Payment', '$10.00', '$4.00'])
            ->assertDontSee('$99.99');

        $this->get('/admin/reports/client-statement?client=nobody@example.com')->assertOk()->assertSee('No client has that number or email address.');
    }

    public function test_tax_is_grouped_by_country_and_rate_and_can_show_only_the_eu(): void
    {
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $this->product->id]);
        $this->paidInvoice($service, 1000, self::PAID_AT, 190, 1900);
        $american = Client::factory()->create(['country' => 'US']);
        $this->paidInvoice(Service::factory()->create(['client_id' => $american->id, 'product_id' => $this->product->id]), 5000, '2026-09-11 09:00:00');

        $csv = $this->get('/admin/reports/tax-collected/export?from=2026-09-01&to=2026-09-30')->streamedContent();
        $this->assertStringContainsString("DE,VAT,19.0,1,10.00,1.90\nUS,\"No tax\",0.0,1,50.00,0.00", $csv);

        $eu = $this->get('/admin/reports/tax-collected/export?from=2026-09-01&to=2026-09-30&region=eu')->streamedContent();
        $this->assertStringNotContainsString('US,', $eu);
    }

    public function test_growth_counts_a_client_lost_when_their_last_service_ends(): void
    {
        Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $this->product->id, 'status' => ServiceStatus::Terminated, 'terminated_at' => '2026-08-10 00:00:00']);
        $stays = Client::factory()->create(['created_at' => '2026-07-05 00:00:00']);
        Service::factory()->create(['client_id' => $stays->id, 'product_id' => $this->product->id]);

        $csv = $this->get('/admin/reports/client-growth/export?from=2026-07-01&to=2026-08-31')->streamedContent();

        $this->assertStringContainsString("2026-07,1,0,2,0.0\n2026-08,0,1,1,50.0", $csv);
    }

    public function test_cancellations_promotions_credit_and_support_reports_add_up(): void
    {
        $promotion = Promotion::create(['code' => 'SAVE10', 'type' => Promotion::PERCENT, 'value' => 1000, 'recurring' => true]);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $this->product->id, 'promotion_id' => $promotion->id, 'status' => ServiceStatus::Suspended]);
        DB::table('services')->where('id', $service->id)->update(['suspension_reason' => 'Overdue on payment']);
        $invoice = $this->paidInvoice($service, 900, self::PAID_AT);
        Order::create(['client_id' => $this->client->id, 'invoice_id' => $invoice->id, 'status' => 'active', 'promotion_id' => $promotion->id, 'promotion_code' => 'SAVE10'])->forceFill(['created_at' => '2026-09-10 08:00:00'])->save();
        CancellationRequest::create(['service_id' => $service->id, 'type' => CancellationRequest::IMMEDIATE, 'reason' => 'Too expensive ', 'cancel_on' => '2026-09-10', 'status' => 'completed']);
        CancellationRequest::create(['service_id' => $service->id, 'type' => CancellationRequest::END_OF_PERIOD, 'reason' => 'too   expensive', 'cancel_on' => '2026-10-10', 'status' => 'withdrawn']);
        CreditEntry::create(['client_id' => $this->client->id, 'amount' => 500, 'balance_after' => 500, 'description' => 'Goodwill']);
        CreditEntry::create(['client_id' => $this->client->id, 'amount' => -200, 'balance_after' => 300, 'description' => 'Applied']);

        $this->get('/admin/reports/promotion-usage?from=2026-09-01&to=2026-10-15')->assertOk()->assertSeeInOrder(['SAVE10', '10%, recurring', '1', '1', '$9.00', '1']);
        $this->get('/admin/reports/suspensions-and-cancellations?from=2026-01-01&to=2026-10-15')->assertOk()
            ->assertSeeInOrder(['Overdue on payment', '1', 'Too expensive', '2', '1', '0', '1']);
        $this->get('/admin/reports/credits?from=2026-10-01&to=2026-10-31')->assertOk()->assertSeeInOrder(['Oct 2026', '$5.00', '$2.00', '$3.00']);

        $department = Department::create(['name' => 'Support']);
        $staff = User::factory()->create(['name' => 'Jane Staff']);
        $ticket = Ticket::create(['client_id' => $this->client->id, 'department_id' => $department->id, 'subject' => 'Down', 'status' => 'closed', 'priority' => 'high', 'closed_at' => '2026-10-02 12:00:00']);
        $ticket->forceFill(['created_at' => '2026-10-02 09:00:00', 'first_response_at' => null])->save();
        TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $staff->id, 'body' => 'Looking', 'internal' => true])->forceFill(['created_at' => '2026-10-02 09:10:00'])->save();
        TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $staff->id, 'body' => 'Fixed'])->forceFill(['created_at' => '2026-10-02 09:30:00'])->save();
        TicketFeedback::create(['ticket_id' => $ticket->id, 'client_id' => $this->client->id, 'rating' => 5]);

        $this->get('/admin/reports/response-times?from=2026-10-01&to=2026-10-15')->assertOk()->assertSeeInOrder(['Support', '1', '1', '30m', '30m', '1', '3h 0m']);
        $this->get('/admin/reports/replies-by-staff?from=2026-10-01&to=2026-10-15')->assertOk()->assertSeeInOrder(['Jane Staff', '1', '1', '1', '1', '5.0']);
    }

    public function test_csv_export_keeps_text_from_running_as_a_formula(): void
    {
        $department = Department::create(['name' => 'Support']);
        $ticket = Ticket::create(['client_id' => $this->client->id, 'department_id' => $department->id, 'subject' => '=cmd', 'status' => 'closed', 'priority' => 'low']);
        TicketFeedback::create(['ticket_id' => $ticket->id, 'client_id' => $this->client->id, 'rating' => 1, 'comment' => '=HYPERLINK("x")']);

        $csv = $this->get('/admin/reports/ticket-feedback/export')->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
    }
}
