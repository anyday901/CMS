<?php

namespace App\Http\Controllers\Portal;

use App\Affiliates\AffiliateProgram;
use App\Affiliates\Payouts;
use App\Affiliates\Referrals;
use App\Http\Controllers\Controller;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

/** The account holder's affiliate page: join, share the link, follow referrals and commissions, ask for a payout. */
class AffiliateController extends Controller
{
    public function show(Request $request, AffiliateProgram $program): View
    {
        $client = $request->user('client');
        $affiliate = $client->affiliate;

        return view('portal.affiliates.show', [
            'client' => $client,
            'program' => $program,
            'affiliate' => $affiliate,
            'referrals' => $affiliate?->referrals()->latest('id')->limit(50)->get() ?? collect(),
            'referralCount' => $affiliate?->referrals()->count() ?? 0,
            'commissions' => $affiliate?->commissions()->latest('id')->limit(50)->get() ?? collect(),
            'payouts' => $affiliate?->payouts()->latest('id')->get() ?? collect(),
            'balances' => $affiliate?->balances() ?? [],
            'pending' => $affiliate?->pendingTotals() ?? [],
        ]);
    }

    public function store(Request $request, AffiliateProgram $program, Referrals $referrals): RedirectResponse
    {
        if (! $program->enabled() || ! $program->selfSignUp()) {
            return back()->withErrors(['affiliate' => __("Joining the affiliate program isn't open at the moment. Please contact us if you'd like to join.")]);
        }

        $referrals->join($request->user('client'));

        return redirect()->route('portal.affiliates.show')->with('status', __('Welcome to the affiliate program. Share your link to start earning.'));
    }

    public function payout(Request $request, Payouts $payouts): RedirectResponse
    {
        $affiliate = $request->user('client')->affiliate;
        abort_if($affiliate === null, 404);
        $data = $request->validate(['currency' => ['required', 'string', 'size:3']]);

        try {
            $payout = $payouts->request($affiliate, strtoupper($data['currency']));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['payout' => $e->getMessage()]);
        }

        return back()->with('status', __("Thanks, we've got your payout request for :amount. You'll see here when it's paid.", ['amount' => Money::format($payout->amount, $payout->currency)]));
    }
}
