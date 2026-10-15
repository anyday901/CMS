<?php

namespace App\Http\Controllers\Admin;

use App\Affiliates\Payouts;
use App\Http\Controllers\Controller;
use App\Models\AffiliatePayout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/** The queue of payout requests, and paying or rejecting them. */
class AffiliatePayoutController extends Controller
{
    public function __construct(private Payouts $payouts) {}

    public function index(): View
    {
        return view('admin.affiliates.payouts', [
            'requested' => AffiliatePayout::with('affiliate.client')->where('status', AffiliatePayout::REQUESTED)->oldest('id')->get(),
            'recent' => AffiliatePayout::with(['affiliate.client', 'user'])->where('status', '!=', AffiliatePayout::REQUESTED)->latest('updated_at')->latest('id')->limit(25)->get(),
        ]);
    }

    public function pay(Request $request, AffiliatePayout $payout): RedirectResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in([AffiliatePayout::CREDIT, AffiliatePayout::MANUAL])],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->payouts->pay($payout, $data['method'], $data['reference'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['payout' => $e->getMessage()]);
        }

        return back()->with('status', $data['method'] === AffiliatePayout::CREDIT ? 'Payout added to the affiliate\'s account credit.' : 'Payout recorded as paid.');
    }

    public function reject(Request $request, AffiliatePayout $payout): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:1000']])['reason'] ?? null;

        try {
            $this->payouts->reject($payout, $reason);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['payout' => $e->getMessage()]);
        }

        return back()->with('status', 'Payout request rejected. Its commissions are back in the affiliate\'s balance.');
    }
}
