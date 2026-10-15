<?php

namespace App\Http\Controllers\Portal;

use App\Auth\PortalLogin;
use App\Auth\TwoFactorChallenge;
use App\Http\Controllers\Controller;
use App\Models\LoginAttempt;
use App\Security\LoginSecurity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** The second step of a portal login, for accounts with two-factor authentication. */
class TwoFactorChallengeController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if (TwoFactorChallenge::pending(LoginAttempt::PORTAL) === null) {
            return redirect()->route('portal.login');
        }

        return view('two-factor.challenge', ['action' => route('portal.two-factor.challenge'), 'layout' => 'portal', 'back' => route('portal.login')]);
    }

    public function store(Request $request, PortalLogin $login, LoginSecurity $security): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:30']]);
        $account = TwoFactorChallenge::pending(LoginAttempt::PORTAL);

        if ($account === null || ! $login->canLogIn($account)) {
            TwoFactorChallenge::clear();

            return redirect()->route('portal.login')->withErrors(['email' => __('That took too long. Please log in again.')]);
        }

        if (! $account->verifyTwoFactor($request->input('code'))) {
            $security->failed(LoginAttempt::PORTAL, $account->email, $account, $request, LoginAttempt::TWO_FACTOR_FAILED);

            if (! TwoFactorChallenge::failed()) {
                return redirect()->route('portal.login')->withErrors(['email' => __('Too many wrong codes. Please log in again.')]);
            }

            throw ValidationException::withMessages(['code' => __("That code isn't right. Check the time on your phone, or use a recovery code.")]);
        }

        $remember = TwoFactorChallenge::remember();
        TwoFactorChallenge::clear();
        $login->complete($request, $account, $remember);

        return PortalLogin::home($account);
    }
}
