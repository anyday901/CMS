<?php

namespace Tests\Feature\Affiliates;

use App\Affiliates\Payouts;
use App\Affiliates\Referrals;
use App\Hooks\HookEvent;
use App\Hooks\Hooks;
use App\Models\Activity;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliatePayout;
use App\Models\Client;
use App\Models\CreditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayoutTest extends TestCase
{
    use RefreshDatabase;

    private Affiliate $affiliate;

    protected function setUp(): void
    {
        parent::setUp();
        config(['affiliates.enabled' => true]);
        $this->affiliate = app(Referrals::class)->join(Client::factory()->create(['currency' => 'USD']));
    }

    private function commission(int $amount, string $status = AffiliateCommission::APPROVED, string $currency = 'USD'): AffiliateCommission
    {
        return AffiliateCommission::create([
            'affiliate_id' => $this->affiliate->id, 'amount' => $amount, 'original_amount' => $amount,
            'currency' => $currency, 'status' => $status, 'available_at' => now(),
        ]);
    }

    private function portal(): static
    {
        return $this->actingAs($this->affiliate->client, 'client');
    }

    public function test_affiliates_ask_for_a_payout_once_their_balance_reaches_the_minimum(): void
    {
        $fired = [];
        app(Hooks::class)->listen('affiliate.payout_requested', function (HookEvent $event) use (&$fired) {
            $fired[] = $event;
        });
        $this->commission(1500);
        $this->commission(5000, AffiliateCommission::PENDING);

        $this->portal()->post(route('portal.affiliates.payouts.store'), ['currency' => 'USD'])->assertSessionHasErrors('payout');
        $this->assertSame(0, AffiliatePayout::count());

        $this->commission(1000);
        $this->post(route('portal.affiliates.payouts.store'), ['currency' => 'usd'])->assertSessionHas('status');

        $payout = AffiliatePayout::sole();
        $this->assertSame([2500, 'USD', AffiliatePayout::REQUESTED], [$payout->amount, $payout->currency, $payout->status]);
        $this->assertSame(2, $payout->commissions()->count());
        $this->assertSame([], $this->affiliate->balances());
        $this->assertCount(1, $fired);
        $this->assertSame(2500, $fired[0]->data['payout']['amount']);
        $this->assertSame(route('admin.affiliates.payouts'), $fired[0]->url);
        $this->get(route('portal.affiliates.show'))->assertOk()->assertSee('Waiting to be paid');

        // Nothing left to ask for.
        $this->post(route('portal.affiliates.payouts.store'), ['currency' => 'USD'])->assertSessionHasErrors('payout');
    }

    public function test_a_turned_off_affiliate_cannot_ask_for_a_payout(): void
    {
        $this->commission(5000);
        $this->affiliate->update(['status' => Affiliate::DISABLED]);

        $this->portal()->post(route('portal.affiliates.payouts.store'), ['currency' => 'USD'])->assertSessionHasErrors('payout');
        $this->assertSame(0, AffiliatePayout::count());
    }

    public function test_staff_pay_a_payout_as_account_credit(): void
    {
        $this->commission(3000);
        $payout = app(Payouts::class)->request($this->affiliate, 'USD');
        $this->actingAs($staff = User::factory()->create());

        $this->get(route('admin.affiliates.payouts'))->assertOk()->assertSee('$30.00')->assertSee('Pay as account credit');
        $this->post(route('admin.affiliates.payouts.pay', $payout), ['method' => 'credit'])->assertSessionHas('status');

        $payout->refresh();
        $this->assertSame([AffiliatePayout::PAID, AffiliatePayout::CREDIT, $staff->id], [$payout->status, $payout->method, $payout->user_id]);
        $this->assertNotNull($payout->paid_at);
        $this->assertSame(3000, $this->affiliate->client->fresh()->credit_balance);
        $this->assertSame("Affiliate commission payout #{$payout->id}", CreditEntry::sole()->description);
        $this->assertSame([AffiliateCommission::PAID], AffiliateCommission::pluck('status')->all());
        $this->assertTrue(Activity::where('client_id', $this->affiliate->client_id)->where('description', 'like', 'Paid an affiliate payout of $30.00 as account credit')->exists());

        $this->post(route('admin.affiliates.payouts.pay', $payout), ['method' => 'credit'])->assertSessionHasErrors('payout');
        $this->assertSame(3000, $this->affiliate->client->fresh()->credit_balance);
    }

    public function test_staff_record_a_payout_paid_outside_the_app_with_a_reference(): void
    {
        $this->commission(3000);
        $payout = app(Payouts::class)->request($this->affiliate, 'USD');
        $this->actingAs(User::factory()->create());

        $this->post(route('admin.affiliates.payouts.pay', $payout), ['method' => 'manual'])->assertSessionHasErrors('payout');
        $this->post(route('admin.affiliates.payouts.pay', $payout), ['method' => 'manual', 'reference' => 'PAYPAL-123'])->assertSessionHas('status');

        $payout->refresh();
        $this->assertSame([AffiliatePayout::PAID, AffiliatePayout::MANUAL, 'PAYPAL-123'], [$payout->status, $payout->method, $payout->reference]);
        $this->assertSame(0, $this->affiliate->client->fresh()->credit_balance);
        $this->get(route('admin.affiliates.show', $this->affiliate))->assertOk()->assertSee('PAYPAL-123');
    }

    public function test_credit_payouts_only_in_the_affiliates_own_currency(): void
    {
        $this->commission(4000, currency: 'EUR');
        $payout = app(Payouts::class)->request($this->affiliate, 'EUR');
        $this->actingAs(User::factory()->create());

        $this->get(route('admin.affiliates.payouts'))->assertOk()->assertDontSee('Pay as account credit')->assertSee('can only be paid outside the app');
        $this->post(route('admin.affiliates.payouts.pay', $payout), ['method' => 'credit'])->assertSessionHasErrors('payout');
        $this->assertSame(AffiliatePayout::REQUESTED, $payout->fresh()->status);
        $this->assertSame(0, $this->affiliate->client->fresh()->credit_balance);
    }

    public function test_rejecting_a_request_puts_the_balance_back(): void
    {
        $this->commission(3000);
        $payout = app(Payouts::class)->request($this->affiliate, 'USD');
        $this->actingAs(User::factory()->create());

        $this->post(route('admin.affiliates.payouts.reject', $payout), ['reason' => 'Please add your PayPal email'])->assertSessionHas('status');

        $this->assertSame([AffiliatePayout::REJECTED, 'Please add your PayPal email'], [$payout->fresh()->status, $payout->fresh()->notes]);
        $this->assertSame(['USD' => 3000], $this->affiliate->balances());
        $this->portal()->get(route('portal.affiliates.show'))->assertSee('Please add your PayPal email');
    }
}
