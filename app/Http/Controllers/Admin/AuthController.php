<?php

namespace App\Http\Controllers\Admin;

use App\Auth\TwoFactorChallenge;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Security\Captcha;
use App\Security\LoginSecurity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function __construct(private LoginSecurity $security) {}

    public function create(): View
    {
        return view('admin.auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        Captcha::check($request, 'staff_login');
        $user = User::where('email', $credentials['email'])->first();
        $this->security->ensureNotLockedOut(LoginAttempt::ADMIN, $credentials['email'], $request, $user);

        if ($user === null || ! LoginSecurity::passwordMatches($user, $credentials['password'])) {
            $this->security->failed(LoginAttempt::ADMIN, $credentials['email'], $user, $request);

            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        if ($user->hasTwoFactor()) {
            TwoFactorChallenge::start(LoginAttempt::ADMIN, $user, $request->boolean('remember'));

            return redirect()->route('admin.two-factor.challenge');
        }

        $this->logIn($request, $user, $request->boolean('remember'));

        return redirect()->intended(route('admin.dashboard'));
    }

    public function challenge(): View|RedirectResponse
    {
        if (TwoFactorChallenge::pending(LoginAttempt::ADMIN) === null) {
            return redirect()->route('admin.login');
        }

        return view('two-factor.challenge', ['action' => route('admin.two-factor.challenge'), 'layout' => 'admin', 'back' => route('admin.login')]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:30']]);
        $user = TwoFactorChallenge::pending(LoginAttempt::ADMIN);

        if ($user === null) {
            return redirect()->route('admin.login')->withErrors(['email' => 'That took too long. Please log in again.']);
        }

        if (! $user->verifyTwoFactor($request->input('code'))) {
            $this->security->failed(LoginAttempt::ADMIN, $user->email, $user, $request, LoginAttempt::TWO_FACTOR_FAILED);

            if (! TwoFactorChallenge::failed()) {
                return redirect()->route('admin.login')->withErrors(['email' => 'Too many wrong codes. Please log in again.']);
            }

            throw ValidationException::withMessages(['code' => 'That code isn\'t right. Check the time on your phone, or use a recovery code.']);
        }

        $remember = TwoFactorChallenge::remember();
        TwoFactorChallenge::clear();
        $this->logIn($request, $user, $remember);

        return redirect()->intended(route('admin.dashboard'));
    }

    private function logIn(Request $request, User $user, bool $remember): void
    {
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        $this->security->succeeded(LoginAttempt::ADMIN, $user, $request);
        Activity::record('Staff logged in', $user);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Activity::record('Staff logged out', $request->user());
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
