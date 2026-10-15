<?php

namespace App\Http\Controllers\Admin;

use App\Affiliates\Referrals;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Affiliate;
use App\Models\AffiliatePayout;
use App\Models\Client;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/** The affiliates list, each affiliate's page, making a client an affiliate and setting who referred a client. */
class AffiliateController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), [Affiliate::ACTIVE, Affiliate::DISABLED], true) ? $request->query('status') : null;

        $affiliates = Affiliate::query()
            ->with('client')
            ->withCount('referrals')
            ->when($status, fn ($q) => $q->where('status', $status))
            // Every word must match the code or the client's name, company or email, so "jane doe" finds Jane Doe.
            ->when($search !== '', function ($q) use ($search) {
                foreach (preg_split('/\s+/', $search) as $word) {
                    $like = '%'.addcslashes($word, '%_\\').'%';
                    $q->where(fn ($q) => $q->where('code', 'like', $like)->orWhereHas('client', fn ($c) => $c
                        ->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                        ->orWhere('company', 'like', $like)->orWhere('email', 'like', $like)));
                }
            })
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.affiliates.index', [
            'affiliates' => $affiliates,
            'search' => $search,
            'status' => $status,
            'requested' => AffiliatePayout::where('status', AffiliatePayout::REQUESTED)->count(),
        ]);
    }

    public function show(Affiliate $affiliate): View
    {
        $affiliate->load('client');

        return view('admin.affiliates.show', [
            'affiliate' => $affiliate,
            'referrals' => $affiliate->referrals()->with('client')->latest('id')->get(),
            'commissions' => $affiliate->commissions()->with(['invoice', 'referral.client'])->latest('id')->limit(100)->get(),
            'payouts' => $affiliate->payouts()->with('user')->latest('id')->get()->each->setRelation('affiliate', $affiliate),
            'balances' => $affiliate->balances(),
            'pending' => $affiliate->pendingTotals(),
        ]);
    }

    /** Staff make a client an affiliate, whether or not clients can join themselves. */
    public function store(Request $request, Referrals $referrals): RedirectResponse
    {
        $client = Client::findOrFail($request->validate(['client_id' => ['required', 'integer']])['client_id']);
        $existing = $client->affiliate;
        $affiliate = $referrals->join($client);

        return redirect()->route('admin.affiliates.show', $affiliate)->with('status', $existing ? 'This client is already an affiliate.' : 'Client added to the affiliate program.');
    }

    /** Turn an affiliate on or off, and give them their own commission rate. */
    public function update(Request $request, Affiliate $affiliate): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([Affiliate::ACTIVE, Affiliate::DISABLED])],
            'commission_type' => ['nullable', Rule::in([Affiliate::PERCENT, Affiliate::FIXED])],
            'commission_amount' => ['nullable', 'required_with:commission_type', Money::rule()],
        ]);

        $value = filled($data['commission_type'] ?? null) ? Money::parse((string) $data['commission_amount']) : null;
        if ($value !== null && $data['commission_type'] === Affiliate::PERCENT && $value > 10000) {
            throw ValidationException::withMessages(['commission_amount' => 'A percentage commission cannot be over 100%.']);
        }

        $affiliate->update([
            'status' => $data['status'],
            'commission_type' => $value === null ? null : $data['commission_type'],
            'commission_value' => $value,
        ]);
        Activity::record("Updated affiliate {$affiliate->code}", $affiliate, ['changed' => array_keys($affiliate->getChanges())]);

        return back()->with('status', 'Affiliate updated.');
    }

    /** Set, change or remove who referred a client, by the affiliate's code. */
    public function referrer(Request $request, Client $client, Referrals $referrals): RedirectResponse
    {
        $code = strtolower(trim((string) $request->validate(['referrer_code' => ['nullable', 'string', 'max:40']])['referrer_code'] ?? ''));
        $affiliate = $code === '' ? null : Affiliate::where('code', $code)->first();

        if ($code !== '' && $affiliate === null) {
            return back()->withErrors(['referrer_code' => 'No affiliate has that code.']);
        }

        try {
            $referrals->setReferrer($client, $affiliate);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['referrer_code' => $e->getMessage()]);
        }

        return back()->with('status', $affiliate ? 'Referrer saved.' : 'Referrer removed.');
    }
}
