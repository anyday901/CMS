<?php

namespace Tests\Feature\Affiliates;

use App\Affiliates\Payouts;
use App\Affiliates\Referrals;
use App\Billing\PaymentRecorder;
use App\Billing\RefundService;
use App\Console\Scheduler;
use App\Hooks\HookEvent;
use App\Hooks\Hooks;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliatePayout;
use App\Models\AffiliateReferral;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CommissionTest extends TestCase
{
    use RefreshDatabase;

    private Affiliate $affiliate;

    private Client $referred;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo('2026-10-15 12:00:00');
        config(['affiliates.enabled' => true]);

        $this->affiliate = app(Referrals::class)->join(Client::factory()->create());
        $this->referred = Client::factory()->create();
        AffiliateReferral::create(['affiliate_id' => $this->affiliate->id, 'client_id' => $this->referred->id]);
    }

    /** An invoice for the referred client with one line, optionally taxed. */
    private function invoice(int $amount, int $taxRate = 0, ?Client $client = null): Invoice
    {
        $client ??= $this->referred;
        $invoice = Invoice::create(['client_id' => $client->id, 'currency' => $client->currency, 'issue_date' => today(), 'due_date' => today()]);
        $invoice->forceFill(['tax_rate' => $taxRate, 'tax_inclusive' => false])->save();
        $invoice->items()->create(['description' => 'Hosting', 'amount' => $amount]);

        return $invoice->recalculate();
    }

    private function pay(Invoice $invoice): Transaction
    {
        return app(PaymentRecorder::class)->record($invoice, $invoice->total, 'cash');
    }

    public function test_a_paid_invoice_earns_a_pending_commission_on_the_amount_before_tax(): void
    {
        $fired = [];
        app(Hooks::class)->listen('affiliate.commission', function (HookEvent $event) use (&$fired) {
            $fired[] = $event;
        });

        $invoice = $this->invoice(2000, 1000); // 20.00 plus 10% tax
        $this->pay($invoice);

        $commission = AffiliateCommission::sole();
        $this->assertSame([$this->affiliate->id, $invoice->id, 200, 200, 'USD', AffiliateCommission::PENDING], [
            $commission->affiliate_id, $commission->invoice_id, $commission->amount, $commission->original_amount, $commission->currency, $commission->status,
        ]);
        $this->assertSame('2026-11-14', $commission->available_at->toDateString());
        $this->assertCount(1, $fired);
        $this->assertSame(200, $fired[0]->data['commission']['amount']);
        $this->assertStringContainsString("invoice #{$invoice->number}", $fired[0]->summary);
        $this->assertSame([], $this->affiliate->balances());
        $this->assertSame(['USD' => 200], $this->affiliate->pendingTotals());
    }

    public function test_tax_inclusive_invoices_take_the_tax_out_first(): void
    {
        $invoice = Invoice::create(['client_id' => $this->referred->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $invoice->forceFill(['tax_rate' => 2500, 'tax_inclusive' => true])->save();
        $invoice->items()->create(['description' => 'Hosting', 'amount' => 2500]);
        $this->pay($invoice->recalculate());

        $this->assertSame(200, AffiliateCommission::sole()->amount);
    }

    public function test_nothing_is_earned_when_there_is_nothing_to_pay_or_the_program_is_off(): void
    {
        app(PaymentRecorder::class)->settleIfNothingDue($this->invoice(0));
        $this->assertSame(0, AffiliateCommission::count());

        config(['affiliates.enabled' => false]);
        $this->pay($this->invoice(1000));
        $this->assertSame(0, AffiliateCommission::count());

        config(['affiliates.enabled' => true]);
        $this->affiliate->update(['status' => Affiliate::DISABLED]);
        $this->pay($this->invoice(1000));
        $this->assertSame(0, AffiliateCommission::count());

        // Clients nobody referred earn nobody anything.
        $this->affiliate->update(['status' => Affiliate::ACTIVE]);
        $this->pay($this->invoice(1000, client: Client::factory()->create()));
        $this->assertSame(0, AffiliateCommission::count());
    }

    public function test_an_affiliates_own_fixed_rate_and_the_first_invoice_and_month_limits(): void
    {
        $this->affiliate->update(['commission_type' => Affiliate::FIXED, 'commission_value' => 500]);
        config(['affiliates.first_invoice_only' => true]);

        $this->pay($this->invoice(2000));
        $this->pay($this->invoice(3000));
        $this->assertSame([500], AffiliateCommission::pluck('amount')->all());

        config(['affiliates.first_invoice_only' => false, 'affiliates.months' => 2]);
        $this->pay($this->invoice(3000));
        $this->assertSame([500, 500], AffiliateCommission::pluck('amount')->all());

        $this->travel(3)->months();
        $this->pay($this->invoice(3000));
        $this->assertSame(2, AffiliateCommission::count());
    }

    public function test_the_nightly_task_approves_commissions_past_their_clearing_date(): void
    {
        $this->pay($this->invoice(2000));
        $this->travel(29)->days();
        $this->pay($this->invoice(1000));

        $this->artisan('affiliates:clear')->expectsOutputToContain('Approved 0')->assertSuccessful();
        $this->travel(1)->days();
        $this->artisan('affiliates:clear')->expectsOutputToContain('Approved 1')->assertSuccessful();

        $this->assertSame([AffiliateCommission::APPROVED, AffiliateCommission::PENDING], AffiliateCommission::orderBy('id')->pluck('status')->all());
        $this->assertSame(['USD' => 200], $this->affiliate->balances());

        $schedule = new Schedule;
        Scheduler::define($schedule);
        $this->assertTrue(collect($schedule->events())->contains(fn ($event) => str_contains($event->command, 'affiliates:clear')));
    }

    public function test_no_clearing_days_approves_at_once(): void
    {
        config(['affiliates.clearing_days' => 0]);
        $this->pay($this->invoice(2000));

        $this->assertSame(AffiliateCommission::APPROVED, AffiliateCommission::sole()->status);
    }

    public function test_a_full_refund_voids_the_commission_and_a_partial_one_lowers_it(): void
    {
        $full = $this->invoice(2000);
        app(RefundService::class)->refund($this->pay($full), 2000, 'manual');
        $voided = AffiliateCommission::where('invoice_id', $full->id)->sole();
        $this->assertSame([AffiliateCommission::VOID, "Invoice #{$full->number} was refunded"], [$voided->status, $voided->void_reason]);

        $partial = $this->invoice(2000);
        $payment = $this->pay($partial);
        app(RefundService::class)->refund($payment, 500, 'manual');
        $lowered = AffiliateCommission::where('invoice_id', $partial->id)->sole();
        $this->assertSame([150, 200, AffiliateCommission::PENDING], [$lowered->amount, $lowered->original_amount, $lowered->status]);

        app(RefundService::class)->refund($payment, 1500, 'credit');
        $this->assertSame(AffiliateCommission::VOID, $lowered->fresh()->status);
    }

    public function test_a_refund_takes_an_approved_commission_out_of_a_waiting_payout_but_leaves_paid_ones(): void
    {
        config(['affiliates.clearing_days' => 0, 'affiliates.minimum_payout' => '1.00']);
        $first = $this->invoice(10000);
        $firstPayment = $this->pay($first);
        $second = $this->invoice(5000);
        $secondPayment = $this->pay($second);

        $payout = app(Payouts::class)->request($this->affiliate, 'USD');
        $this->assertSame(1500, $payout->amount);

        app(RefundService::class)->refund($secondPayment, 5000, 'manual');
        $this->assertSame([1000, AffiliatePayout::REQUESTED], [$payout->fresh()->amount, $payout->fresh()->status]);
        $this->assertNull(AffiliateCommission::where('invoice_id', $second->id)->value('payout_id'));

        $this->actingAs(User::factory()->create());
        app(Payouts::class)->pay($payout, AffiliatePayout::CREDIT);
        app(RefundService::class)->refund($firstPayment, 10000, 'manual');
        $this->assertSame([1000, AffiliateCommission::PAID], [AffiliateCommission::where('invoice_id', $first->id)->value('amount'), AffiliateCommission::where('invoice_id', $first->id)->value('status')]);
    }

    public function test_staff_approve_and_void_commissions_by_hand(): void
    {
        $this->actingAs(User::factory()->create());
        $this->pay($this->invoice(2000));
        $this->pay($this->invoice(1000));
        [$one, $two] = AffiliateCommission::orderBy('id')->get();

        $this->post(route('admin.affiliates.commissions.approve', $one))->assertSessionHas('status');
        $this->post(route('admin.affiliates.commissions.void', $two), ['reason' => 'Fraudulent order'])->assertSessionHas('status');

        $this->assertSame(AffiliateCommission::APPROVED, $one->fresh()->status);
        $this->assertSame([AffiliateCommission::VOID, 'Fraudulent order'], [$two->fresh()->status, $two->fresh()->void_reason]);
        $this->post(route('admin.affiliates.commissions.approve', $two))->assertSessionHasErrors('commission');
        $this->post(route('admin.affiliates.commissions.void', $two))->assertSessionHasErrors('commission');
    }
}
