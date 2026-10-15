<?php

namespace Tests\Feature\Affiliates;

use App\Affiliates\Referrals;
use App\Clients\ClientMerger;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliatePayout;
use App\Models\AffiliateReferral;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AffiliateMergeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->actingAs(User::factory()->create());
    }

    private function refer(Affiliate $affiliate, Client $client): AffiliateReferral
    {
        return AffiliateReferral::create(['affiliate_id' => $affiliate->id, 'client_id' => $client->id]);
    }

    public function test_the_merged_accounts_affiliate_and_referrer_move_to_the_account_kept(): void
    {
        $keep = Client::factory()->create();
        $old = Client::factory()->create();
        $affiliate = app(Referrals::class)->join($old);
        $referred = Client::factory()->create();
        $this->refer($affiliate, $referred);
        $referrer = app(Referrals::class)->join(Client::factory()->create());
        $this->refer($referrer, $old);

        app(ClientMerger::class)->merge($old, $keep);

        $this->assertSame($keep->id, $affiliate->fresh()->client_id);
        $this->assertSame($affiliate->id, $referred->referral()->value('affiliate_id'));
        $this->assertSame($referrer->id, $keep->referral()->value('affiliate_id'));
    }

    public function test_when_both_are_affiliates_the_kept_one_takes_everything_over(): void
    {
        $keep = Client::factory()->create();
        $old = Client::factory()->create();
        $kept = app(Referrals::class)->join($keep);
        $kept->forceFill(['visits' => 3])->save();
        $gone = app(Referrals::class)->join($old);
        $gone->forceFill(['visits' => 4])->save();
        $this->refer($gone, $referred = Client::factory()->create());
        AffiliateCommission::create(['affiliate_id' => $gone->id, 'amount' => 500, 'original_amount' => 500, 'currency' => 'USD', 'status' => AffiliateCommission::APPROVED]);
        AffiliatePayout::create(['affiliate_id' => $gone->id, 'amount' => 900, 'currency' => 'USD', 'status' => AffiliatePayout::PAID]);

        app(ClientMerger::class)->merge($old, $keep);

        $this->assertNull($gone->fresh());
        $kept->refresh();
        $this->assertSame(7, $kept->visits);
        $this->assertSame($kept->id, $referred->referral()->value('affiliate_id'));
        $this->assertSame(['USD' => 500], $kept->balances());
        $this->assertSame(1, $kept->payouts()->count());
    }

    public function test_the_kept_accounts_own_referrer_wins_and_nobody_refers_themselves(): void
    {
        $keep = Client::factory()->create();
        $old = Client::factory()->create();
        $first = app(Referrals::class)->join(Client::factory()->create());
        $second = app(Referrals::class)->join(Client::factory()->create());
        $this->refer($first, $keep);
        $this->refer($second, $old);

        app(ClientMerger::class)->merge($old, $keep);
        $this->assertSame([$first->id], AffiliateReferral::where('client_id', $keep->id)->pluck('affiliate_id')->all());

        // An affiliate merged into the client they referred.
        $affiliateClient = Client::factory()->create();
        $affiliate = app(Referrals::class)->join($affiliateClient);
        $customer = Client::factory()->create();
        $this->refer($affiliate, $customer);

        app(ClientMerger::class)->merge($affiliateClient, $customer);
        $this->assertSame($customer->id, $affiliate->fresh()->client_id);
        $this->assertNull($customer->referral()->first());
    }
}
