<?php

namespace App\Import\Whmcs;

use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliatePayout;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * WHMCS affiliates: tblaffiliates (the affiliates), tblaffiliatesaccounts
 * (the services they referred), tblaffiliatespending (commissions still
 * clearing), tblaffiliateshistory (cleared commissions) and
 * tblaffiliateswithdrawals (payouts).
 *
 * Each affiliate's code is their WHMCS id, so aff.php?aff=ID links keep
 * working. WHMCS refers services, not clients, so a client becomes the
 * referral of the affiliate of their first referred service. WHMCS doesn't
 * link withdrawals to commissions, so cleared commissions come over as
 * paid and the WHMCS balance as one approved commission, which keeps each
 * affiliate's balance exactly as it was.
 */
class AffiliateImporter
{
    private const AFFILIATES = 'Affiliates';

    private const REFERRALS = 'Affiliate referrals';

    private const COMMISSIONS = 'Affiliate commissions';

    private const PAYOUTS = 'Affiliate payouts';

    /** @var array<int, array{id: int, client: int, currency: string}> WHMCS affiliate id => the affiliate here */
    private array $affiliates = [];

    /** @var array<int, array{affiliate: int, referral: ?int}> WHMCS tblaffiliatesaccounts id => affiliate and referral here */
    private array $accounts = [];

    /** @var array<int, string> affiliate id here => their currency */
    private array $currencies = [];

    /** @var array<int, true> invoices that already have a commission, since each can have one */
    private array $invoicesUsed = [];

    /**
     * @param  array<int, string>  $clients  the imported clients, by id
     * @param  array<int, string>  $invoices  the imported invoices, by id
     */
    public function __construct(private ImportRun $run, private array $clients, private array $invoices) {}

    public function import(): void
    {
        if (! $this->run->hasTables('tblaffiliates')) {
            return;
        }

        $this->importAffiliates();
        if ($this->run->hasTables('tblaffiliatesaccounts')) {
            $this->importAccounts();
        }
        if ($this->run->hasTables('tblaffiliatespending')) {
            $this->importPending();
        }
        if ($this->run->hasTables('tblaffiliateshistory')) {
            $this->importHistory();
        }
        if ($this->run->hasTables('tblaffiliateswithdrawals')) {
            $this->importWithdrawals();
        }
        $this->importSettings();
    }

    private function importAffiliates(): void
    {
        $oneTime = 0;

        foreach ($this->run->table('tblaffiliates')->orderBy('id')->cursor() as $row) {
            $this->run->report->found(self::AFFILIATES);
            $clientId = (int) $row->clientid;

            if (! isset($this->clients[$clientId]) || in_array($clientId, array_column($this->affiliates, 'client'), true)) {
                $this->run->report->skipped(self::AFFILIATES, "Affiliate {$row->id} belongs to client {$row->clientid}, which wasn't imported or is already an affiliate.");

                continue;
            }

            [$type, $value] = match (strtolower(trim((string) ($row->paytype ?? '')))) {
                'percentage' => [Affiliate::PERCENT, Whmcs::cents($row->payamount ?? 0)],
                'fixedamount', 'fixed' => [Affiliate::FIXED, Whmcs::cents($row->payamount ?? 0)],
                default => [null, null],
            };
            $created = Whmcs::dateTime($row->date ?? null) ?? $this->run->now;
            $id = (int) DB::table('affiliates')->insertGetId([
                'client_id' => $clientId,
                'code' => (string) $row->id,
                'status' => Affiliate::ACTIVE,
                'commission_type' => $type,
                'commission_value' => $value,
                'visits' => max(0, (int) ($row->visitors ?? 0)),
                'created_at' => $created,
                'updated_at' => $this->run->now,
            ]);
            $this->affiliates[(int) $row->id] = ['id' => $id, 'client' => $clientId, 'currency' => $this->clients[$clientId]];
            $this->currencies[$id] = $this->clients[$clientId];
            $this->run->report->imported(self::AFFILIATES);
            $oneTime += Whmcs::on($row->onetime ?? '') ? 1 : 0;

            // What WHMCS says is still theirs to withdraw.
            $balance = Whmcs::cents($row->balance ?? 0);
            if ($balance > 0) {
                $this->commission($id, $balance, AffiliateCommission::APPROVED, 'Balance brought over from WHMCS', $this->run->now);
            }
        }

        if ($oneTime > 0) {
            $this->run->report->note(self::AFFILIATES, "{$oneTime} affiliate(s) were paid once per referral in WHMCS. Here that is one setting for everyone (pay on the first invoice only, under Affiliates > Settings), so check it suits them.");
        }
    }

    private function importAccounts(): void
    {
        $services = $this->run->table('tblhosting')->pluck('userid', 'id')->map(fn ($id) => (int) $id)->all();
        $referred = [];

        foreach ($this->run->table('tblaffiliatesaccounts')->orderBy('id')->cursor() as $row) {
            $this->run->report->found(self::REFERRALS);
            $affiliate = $this->affiliates[(int) $row->affiliateid] ?? null;
            $clientId = $services[(int) $row->relid] ?? null;

            if ($affiliate === null || $clientId === null || ! isset($this->clients[$clientId]) || $clientId === $affiliate['client']) {
                $this->run->report->skipped(self::REFERRALS, "Referral {$row->id} (service {$row->relid}) is for an affiliate or client that wasn't imported, or the affiliate's own service.");

                continue;
            }

            if (isset($referred[$clientId])) {
                // Another service of a client already referred: its commissions still count, under the first referral.
                $this->accounts[(int) $row->id] = ['affiliate' => $affiliate['id'], 'referral' => $referred[$clientId]['affiliate'] === $affiliate['id'] ? $referred[$clientId]['id'] : null];
                if ($referred[$clientId]['affiliate'] !== $affiliate['id']) {
                    $this->run->report->skipped(self::REFERRALS, "Client {$clientId} had services referred by more than one affiliate; they stay the referral of the first, and the others keep the commissions they earned.");
                }

                continue;
            }

            $id = (int) DB::table('affiliate_referrals')->insertGetId([
                'affiliate_id' => $affiliate['id'],
                'client_id' => $clientId,
                'created_at' => $this->run->now,
                'updated_at' => $this->run->now,
            ]);
            $referred[$clientId] = ['id' => $id, 'affiliate' => $affiliate['id']];
            $this->accounts[(int) $row->id] = ['affiliate' => $affiliate['id'], 'referral' => $id];
            $this->run->report->imported(self::REFERRALS);
        }
    }

    private function importPending(): void
    {
        foreach ($this->run->table('tblaffiliatespending')->orderBy('id')->cursor() as $row) {
            $this->run->report->found(self::COMMISSIONS);
            $account = $this->accounts[(int) $row->affaccid] ?? null;
            $amount = Whmcs::cents($row->amount ?? 0);

            if ($account === null || $amount <= 0) {
                $this->run->report->skipped(self::COMMISSIONS, "Pending commission {$row->id} is for a referral that wasn't imported, or is for nothing.");

                continue;
            }

            $clearing = Whmcs::dateTime($row->clearingdate ?? null) ?? $this->run->now;
            $this->commission($account['affiliate'], $amount, AffiliateCommission::PENDING, 'Pending in WHMCS', $clearing, $account['referral'], $row->invoice_id ?? null);
            $this->run->report->imported(self::COMMISSIONS);
        }
    }

    private function importHistory(): void
    {
        foreach ($this->run->table('tblaffiliateshistory')->orderBy('id')->cursor() as $row) {
            $this->run->report->found(self::COMMISSIONS);
            $affiliate = $this->affiliates[(int) $row->affiliateid] ?? null;
            $amount = Whmcs::cents($row->amount ?? 0);

            if ($affiliate === null || $amount === 0) {
                $this->run->report->skipped(self::COMMISSIONS, "Commission {$row->id} belongs to affiliate {$row->affiliateid}, which wasn't imported, or is for nothing.");

                continue;
            }

            $date = Whmcs::dateTime($row->date ?? null) ?? $this->run->now;
            $description = Whmcs::short($row->description ?? '') ?? 'Commission in WHMCS';
            $this->commission($affiliate['id'], $amount, AffiliateCommission::PAID, $description, $date, $this->accounts[(int) ($row->affaccid ?? 0)]['referral'] ?? null, $row->invoice_id ?? null);
            $this->run->report->imported(self::COMMISSIONS);
        }

        $this->run->report->note(self::COMMISSIONS, 'Commissions WHMCS had already cleared show as paid, and each affiliate\'s WHMCS balance as one approved commission ("Balance brought over from WHMCS"), since WHMCS doesn\'t record which commissions each withdrawal paid.');
    }

    private function importWithdrawals(): void
    {
        foreach ($this->run->table('tblaffiliateswithdrawals')->orderBy('id')->cursor() as $row) {
            $this->run->report->found(self::PAYOUTS);
            $affiliate = $this->affiliates[(int) $row->affiliateid] ?? null;
            $amount = Whmcs::cents($row->amount ?? 0);

            if ($affiliate === null || $amount <= 0) {
                $this->run->report->skipped(self::PAYOUTS, "Withdrawal {$row->id} belongs to affiliate {$row->affiliateid}, which wasn't imported, or is for nothing.");

                continue;
            }

            $date = Whmcs::dateTime($row->date ?? null) ?? $this->run->now;
            $this->run->queue('affiliate_payouts', [
                'affiliate_id' => $affiliate['id'],
                'amount' => $amount,
                'currency' => $affiliate['currency'],
                'status' => AffiliatePayout::PAID,
                'method' => AffiliatePayout::MANUAL,
                'reference' => "WHMCS withdrawal {$row->id}",
                'paid_at' => $date,
                'created_at' => $date,
                'updated_at' => $date,
            ]);
            $this->run->report->imported(self::PAYOUTS);
        }
    }

    /**
     * WHMCS's program settings: whether it was on, the commission percent,
     * the minimum payout and the clearing days. Only saved by a real import.
     */
    private function importSettings(): void
    {
        $values = array_filter([
            'affiliates.enabled' => $this->run->setting('AffiliateEnabled') !== null ? Whmcs::on($this->run->setting('AffiliateEnabled')) : null,
            'affiliates.commission_amount' => is_numeric($this->run->setting('AffiliateEarningPercent')) ? (string) $this->run->setting('AffiliateEarningPercent') : null,
            'affiliates.minimum_payout' => is_numeric($this->run->setting('AffiliatePayout')) ? (string) $this->run->setting('AffiliatePayout') : null,
            'affiliates.clearing_days' => is_numeric($this->run->setting('AffiliatesDelayCommission')) ? (int) $this->run->setting('AffiliatesDelayCommission') : null,
        ], fn ($value) => $value !== null);

        if ($values === []) {
            return;
        }

        if (isset($values['affiliates.commission_amount'])) {
            $values['affiliates.commission_type'] = Affiliate::PERCENT;
        }
        $this->run->report->note(self::AFFILIATES, 'The affiliate settings came over from WHMCS ('.(($values['affiliates.enabled'] ?? false) ? 'program on' : 'program off')
            .'). Check them under Affiliates > Settings; WHMCS\'s bonus deposit and per-product commissions aren\'t brought over.');

        if (! $this->run->report->dryRun) {
            Settings::save($values);
        }
    }

    /** A commission for an imported affiliate, in their currency. */
    private function commission(int $affiliateId, int $amount, string $status, string $description, string $date, ?int $referralId = null, mixed $whmcsInvoiceId = null): void
    {
        $invoiceId = (int) $whmcsInvoiceId;
        $invoiceId = $invoiceId > 0 && isset($this->invoices[$invoiceId]) && ! isset($this->invoicesUsed[$invoiceId]) ? $invoiceId : null;
        if ($invoiceId !== null) {
            $this->invoicesUsed[$invoiceId] = true;
        }

        $this->run->queue('affiliate_commissions', [
            'affiliate_id' => $affiliateId,
            'referral_id' => $referralId,
            'invoice_id' => $invoiceId,
            'description' => $description,
            'amount' => $amount,
            'original_amount' => $amount,
            'currency' => $this->currencies[$affiliateId],
            'status' => $status,
            'available_at' => $date,
            'approved_at' => $status === AffiliateCommission::PENDING ? null : $date,
            'created_at' => $status === AffiliateCommission::PENDING ? $this->run->now : $date,
            'updated_at' => $this->run->now,
        ]);
    }
}
