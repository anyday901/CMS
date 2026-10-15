<?php

namespace App\Affiliates;

use App\Billing\CreditLedger;
use App\Models\Activity;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliatePayout;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Affiliates ask for their approved balance in one currency; staff pay it
 * as account credit (in the affiliate's own currency only) or outside the
 * app with a reference, or reject it, which puts the balance back.
 */
class Payouts
{
    public function __construct(private AffiliateProgram $program, private CreditLedger $ledger) {}

    /** @throws InvalidArgumentException when there is nothing to ask for yet */
    public function request(Affiliate $affiliate, string $currency): AffiliatePayout
    {
        $payout = DB::transaction(function () use ($affiliate, $currency) {
            $affiliate = Affiliate::lockForUpdate()->findOrFail($affiliate->id);

            if (! $affiliate->isActive()) {
                throw new InvalidArgumentException('Your affiliate account is turned off, so payouts can\'t be requested. Please contact us.');
            }

            $commissions = $affiliate->commissions()->where('status', AffiliateCommission::APPROVED)->whereNull('payout_id')->where('currency', $currency);
            $balance = (int) (clone $commissions)->sum('amount');
            $minimum = $this->program->minimumPayout();

            if ($balance <= 0 || $balance < $minimum) {
                throw new InvalidArgumentException('You can ask for a payout once your balance reaches '.Money::format($minimum, $currency).'.');
            }

            $payout = AffiliatePayout::create(['affiliate_id' => $affiliate->id, 'amount' => $balance, 'currency' => $currency]);
            $commissions->update(['payout_id' => $payout->id]);
            Activity::record('Asked for an affiliate payout of '.Money::format($balance, $currency), $payout, [], $affiliate->client);

            return $payout;
        });

        AffiliateHooks::payoutRequested($payout);

        return $payout;
    }

    /**
     * Pays a requested payout: as account credit, or recorded as sent
     * outside the app with a reference such as a PayPal transaction id.
     */
    public function pay(AffiliatePayout $payout, string $method, ?string $reference = null): void
    {
        DB::transaction(function () use ($payout, $method, $reference) {
            $payout = $this->lockRequested($payout);
            $client = $payout->affiliate->client;

            if (! in_array($method, [AffiliatePayout::CREDIT, AffiliatePayout::MANUAL], true)) {
                throw new InvalidArgumentException('Choose how the payout was paid.');
            }

            if ($method === AffiliatePayout::CREDIT && $payout->currency !== $client->currency) {
                throw new InvalidArgumentException("This payout is in {$payout->currency} and {$client->fullName()} is billed in {$client->currency}, so it can't be paid as account credit. Pay it outside the app instead.");
            }

            if ($method === AffiliatePayout::MANUAL && blank($reference)) {
                throw new InvalidArgumentException('Enter a reference for the payment, such as the PayPal or bank transaction id.');
            }

            if ($method === AffiliatePayout::CREDIT) {
                $this->ledger->change($client->id, $payout->amount, "Affiliate commission payout #{$payout->id}");
            }

            $payout->update([
                'status' => AffiliatePayout::PAID,
                'method' => $method,
                'reference' => $method === AffiliatePayout::MANUAL ? trim((string) $reference) : null,
                'user_id' => Auth::guard('web')->id(),
                'paid_at' => now(),
            ]);
            $payout->commissions()->update(['status' => AffiliateCommission::PAID]);

            Activity::record('Paid an affiliate payout of '.Money::format($payout->amount, $payout->currency)
                .($method === AffiliatePayout::CREDIT ? ' as account credit' : " outside the app (reference {$payout->reference})"), $payout, [], $client);
        });
    }

    /** Rejects a request; its commissions go back to the affiliate's balance. */
    public function reject(AffiliatePayout $payout, ?string $reason = null): void
    {
        DB::transaction(function () use ($payout, $reason) {
            $payout = $this->lockRequested($payout);
            $payout->commissions()->update(['payout_id' => null]);
            $payout->update(['status' => AffiliatePayout::REJECTED, 'notes' => filled($reason) ? trim($reason) : null, 'user_id' => Auth::guard('web')->id()]);

            Activity::record('Rejected an affiliate payout request of '.Money::format($payout->amount, $payout->currency).(filled($reason) ? ": {$reason}" : ''), $payout, [], $payout->affiliate->client);
        });
    }

    private function lockRequested(AffiliatePayout $payout): AffiliatePayout
    {
        $payout = AffiliatePayout::with('affiliate.client')->lockForUpdate()->findOrFail($payout->id);

        if ($payout->status !== AffiliatePayout::REQUESTED) {
            throw new InvalidArgumentException("This payout is already {$payout->status}.");
        }

        return $payout;
    }
}
