<?php

namespace App\Http\Controllers\Store;

use App\Affiliates\Referrals;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Referral links: /r/{code} remembers the affiliate and sends the visitor on to the store. */
class AffiliateLinkController extends Controller
{
    public function visit(Request $request, Referrals $referrals, string $code): RedirectResponse
    {
        $referrals->visit($request, $code);

        return redirect()->to($this->destination($request->query('to')));
    }

    /** WHMCS's aff.php?aff=123 links, so links shared before switching keep working with imported affiliates. */
    public function whmcs(Request $request, Referrals $referrals): RedirectResponse
    {
        $referrals->visit($request, (string) $request->query('aff', ''));

        return redirect()->route('store.index');
    }

    /**
     * Where to go next: a path on this site from ?to=, or the store. Only
     * a plain path starting with one slash is followed, never another site.
     */
    private function destination(mixed $to): string
    {
        $safe = is_string($to)
            && preg_match('#^/(?![/\\\\])[^\s\\\\]*$#', $to)
            && parse_url($to, PHP_URL_HOST) === null
            && ! preg_match('/[\x00-\x1f\x7f]/', $to);

        return $safe ? url($to) : route('store.index');
    }
}
