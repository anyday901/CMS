<?php

namespace Tests\Feature\Affiliates;

use App\Import\Whmcs\WhmcsImporter;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliatePayout;
use App\Models\AffiliateReferral;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WhmcsDatabase;
use Tests\TestCase;

class WhmcsAffiliateImportTest extends TestCase
{
    use RefreshDatabase;

    private ConnectionInterface $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 12:00:00');
        $this->whmcs = WhmcsDatabase::create();
        WhmcsDatabase::seed($this->whmcs);
        $this->affiliateTables();
    }

    /**
     * WHMCS's affiliate tables, with the columns the importer reads: client 8
     * (Grace) is an affiliate with a custom 15% rate who referred Ada's VPS
     * (service 40) and has a pending commission on invoice 100, a cleared
     * one, a withdrawal and 7.50 left to withdraw.
     */
    private function affiliateTables(): void
    {
        $schema = $this->whmcs->getSchemaBuilder();
        $schema->create('tblaffiliates', function (Blueprint $t) {
            $t->id();
            $t->date('date')->nullable();
            $t->integer('clientid');
            $t->integer('visitors')->default(0);
            $t->string('paytype')->default('');
            $t->decimal('payamount', 16, 2)->default(0);
            $t->integer('onetime')->default(0);
            $t->decimal('balance', 16, 2)->default(0);
            $t->decimal('withdrawn', 16, 2)->default(0);
        });
        $schema->create('tblaffiliatesaccounts', function (Blueprint $t) {
            $t->id();
            $t->integer('affiliateid');
            $t->integer('relid');
            $t->date('lastpaid')->nullable();
        });
        $schema->create('tblaffiliatespending', function (Blueprint $t) {
            $t->id();
            $t->integer('affaccid');
            $t->decimal('amount', 16, 2);
            $t->date('clearingdate')->nullable();
            $t->integer('invoice_id')->default(0);
        });
        $schema->create('tblaffiliateshistory', function (Blueprint $t) {
            $t->id();
            $t->integer('affiliateid');
            $t->date('date')->nullable();
            $t->integer('affaccid')->default(0);
            $t->text('description')->nullable();
            $t->decimal('amount', 16, 2);
            $t->integer('invoice_id')->default(0);
        });
        $schema->create('tblaffiliateswithdrawals', function (Blueprint $t) {
            $t->id();
            $t->integer('affiliateid');
            $t->date('date')->nullable();
            $t->decimal('amount', 16, 2);
        });

        $this->whmcs->table('tblclients')->insert(['id' => 8, 'firstname' => 'Grace', 'lastname' => 'Hopper', 'email' => 'grace@example.com', 'status' => 'Active', 'currency' => 1, 'credit' => '0', 'password' => '']);
        $this->whmcs->table('tblaffiliates')->insert([
            ['id' => 3, 'date' => '2023-05-01', 'clientid' => 8, 'visitors' => 41, 'paytype' => 'percentage', 'payamount' => '15.00', 'onetime' => 1, 'balance' => '7.50', 'withdrawn' => '30.00'],
            ['id' => 4, 'date' => '2023-05-01', 'clientid' => 99, 'visitors' => 0, 'paytype' => '', 'payamount' => '0', 'onetime' => 0, 'balance' => '0', 'withdrawn' => '0'],
        ]);
        $this->whmcs->table('tblaffiliatesaccounts')->insert(['id' => 20, 'affiliateid' => 3, 'relid' => 40]);
        $this->whmcs->table('tblaffiliatespending')->insert(['affaccid' => 20, 'amount' => '1.95', 'clearingdate' => '2026-11-07', 'invoice_id' => 100]);
        $this->whmcs->table('tblaffiliateshistory')->insert(['affiliateid' => 3, 'date' => '2024-02-01', 'affaccid' => 20, 'description' => 'Commission for VPS', 'amount' => '37.50', 'invoice_id' => 0]);
        $this->whmcs->table('tblaffiliateswithdrawals')->insert(['affiliateid' => 3, 'date' => '2024-03-01', 'amount' => '30.00']);
        $this->whmcs->table('tblconfiguration')->insert([
            ['setting' => 'AffiliateEnabled', 'value' => 'on'],
            ['setting' => 'AffiliateEarningPercent', 'value' => '12'],
            ['setting' => 'AffiliatePayout', 'value' => '50.00'],
            ['setting' => 'AffiliatesDelayCommission', 'value' => '21'],
        ]);
    }

    public function test_a_dry_run_counts_affiliates_without_saving_them_or_the_settings(): void
    {
        $report = (new WhmcsImporter)->run($this->whmcs, dryRun: true);

        $this->assertSame(['found' => 2, 'imported' => 1, 'skipped' => 1], $report->counts()['Affiliates']);
        $this->assertSame(['found' => 1, 'imported' => 1, 'skipped' => 0], $report->counts()['Affiliate referrals']);
        $this->assertSame(['found' => 2, 'imported' => 2, 'skipped' => 0], $report->counts()['Affiliate commissions']);
        $this->assertSame(['found' => 1, 'imported' => 1, 'skipped' => 0], $report->counts()['Affiliate payouts']);
        $this->assertSame(0, Affiliate::count());
        $this->assertFalse((bool) config('affiliates.enabled'));
    }

    public function test_affiliates_come_over_with_their_referrals_commissions_payouts_and_balance(): void
    {
        $report = (new WhmcsImporter)->run($this->whmcs, dryRun: false);

        $affiliate = Affiliate::sole();
        $this->assertSame([8, '3', 41, Affiliate::PERCENT, 1500], [$affiliate->client_id, $affiliate->code, $affiliate->visits, $affiliate->commission_type, $affiliate->commission_value]);
        $this->assertSame($affiliate->id, AffiliateReferral::where('client_id', 7)->value('affiliate_id'));

        $pending = AffiliateCommission::where('status', AffiliateCommission::PENDING)->sole();
        $this->assertSame([195, 100, '2026-11-07'], [$pending->amount, $pending->invoice_id, $pending->available_at->toDateString()]);
        $this->assertSame(['Commission for VPS' => 3750], AffiliateCommission::where('status', AffiliateCommission::PAID)->pluck('amount', 'description')->all());
        $this->assertSame(['USD' => 750], $affiliate->balances());

        $payout = AffiliatePayout::sole();
        $this->assertSame([3000, AffiliatePayout::PAID, AffiliatePayout::MANUAL], [$payout->amount, $payout->status, $payout->method]);

        $this->assertTrue(config('affiliates.enabled'));
        $this->assertSame(['12', '50.00', 21], [config('affiliates.commission_amount'), config('affiliates.minimum_payout'), config('affiliates.clearing_days')]);
        $this->assertStringContainsString('paid once per referral', implode(' ', $report->notes()['Affiliates']));

        // Links shared from WHMCS still work.
        $this->get('/aff.php?aff=3')->assertRedirect(route('store.index'));
        $this->assertSame(42, $affiliate->fresh()->visits);
    }
}
