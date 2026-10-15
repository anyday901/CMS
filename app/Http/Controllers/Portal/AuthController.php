<?php

namespace App\Http\Controllers\Portal;

use App\Auth\PortalLogin;
use App\Auth\TwoFactorChallenge;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\LoginAttempt;
use App\Security\Captcha;
use App\Security\LoginSecurity;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function __construct(private PortalLogin $login, private LoginSecurity $security) {}

    public function create(): View
    {
        return view('portal.auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        Captcha::check($request, 'login');
        $owner = $this->login->owner($credentials['email']);
        $this->security->ensureNotLockedOut(LoginAttempt::PORTAL, $credentials['email'], $request, $owner);

        $account = $this->login->find($credentials['email'], $credentials['password']);

        if ($account === null) {
            $this->security->failed(LoginAttempt::PORTAL, $credentials['email'], $owner, $request);

            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        if ($account->hasTwoFactor()) {
            TwoFactorChallenge::start(LoginAttempt::PORTAL, $account, $request->boolean('remember'));

            return redirect()->route('portal.two-factor.challenge');
        }

        $this->login->complete($request, $account, $request->boolean('remember'));

        return PortalLogin::home($account);
    }

    public function destroy(Request $request): RedirectResponse
    {
        // Staff signed in as this client: leave the client account, keep the staff login.
        if (Impersonation::active()) {
            $client = Impersonation::stop();

            return redirect()->route('admin.clients.show', $client);
        }

        Activity::record('Logged out of the portal', $request->user('client'));
        PortalLogin::logout($request);

        return redirect()->route('portal.login');
    }
}
