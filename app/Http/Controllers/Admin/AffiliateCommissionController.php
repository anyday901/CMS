<?php

namespace App\Http\Controllers\Admin;

use App\Affiliates\Commissions;
use App\Http\Controllers\Controller;
use App\Models\AffiliateCommission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Staff approve a commission before its clearing date, or void one that shouldn't be paid. */
class AffiliateCommissionController extends Controller
{
    public function __construct(private Commissions $commissions) {}

    public function approve(AffiliateCommission $commission): RedirectResponse
    {
        try {
            $this->commissions->approve($commission);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['commission' => $e->getMessage()]);
        }

        return back()->with('status', 'Commission approved. It now counts towards the affiliate\'s balance.');
    }

    public function void(Request $request, AffiliateCommission $commission): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;

        try {
            $this->commissions->void($commission, filled($reason) ? trim($reason) : 'Voided by staff');
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['commission' => $e->getMessage()]);
        }

        return back()->with('status', 'Commission voided.');
    }
}
