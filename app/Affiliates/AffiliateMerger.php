<?php

namespace App\Affiliates;

use App\Models\Affiliate;
use App\Models\AffiliateReferral;
use App\Models\Client;
use Illuminate\Support\Facades\DB;

/**
 * The affiliate side of merging two client accounts, called by ClientMerger
 * inside its transaction.
 *
 * - The merged account's affiliate moves to the account kept. When both are
 *   affiliates, the kept one's code and rate stay, and the other's
 *   referrals, commissions, payouts and visits join it; its old link stops
 *   working.
 * - Who referred the merged account moves too, unless the kept account
 *   already has a referrer, which wins.
 * - Nobody ends up as their own referral.
 */
class AffiliateMerger
{
    public function merge(Client $from, Client $into): void
    {
        $this->mergeAffiliate($from, $into);
        $this->mergeReferral($from, $into);

        // A merged pair where one referred the other.
        AffiliateReferral::where('client_id', $into->id)
            ->whereIn('affiliate_id', Affiliate::where('client_id', $into->id)->select('id'))
            ->delete();
    }

    private function mergeAffiliate(Client $from, Client $into): void
    {
        $old = Affiliate::where('client_id', $from->id)->first();

        if ($old === null) {
            return;
        }

        $kept = Affiliate::where('client_id', $into->id)->first();

        if ($kept === null) {
            $old->client_id = $into->id;
            $old->save();

            return;
        }

        foreach (['affiliate_referrals', 'affiliate_commissions', 'affiliate_payouts'] as $table) {
            DB::table($table)->where('affiliate_id', $old->id)->update(['affiliate_id' => $kept->id]);
        }
        $kept->increment('visits', $old->visits);
        $old->delete();
    }

    private function mergeReferral(Client $from, Client $into): void
    {
        $old = AffiliateReferral::where('client_id', $from->id)->first();

        if ($old === null) {
            return;
        }

        if (AffiliateReferral::where('client_id', $into->id)->exists()) {
            // Its commissions stay with the affiliate who earned them, without the referral.
            $old->delete();

            return;
        }

        $old->client_id = $into->id;
        $old->save();
    }
}
