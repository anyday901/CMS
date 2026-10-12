<?php

namespace App\Http\Controllers\Portal;

use App\Auth\LegacyPassword;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Client;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
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

        $ok = Auth::guard('client')->attemptWhen(
            $credentials,
            fn (Client $client) => $client->canLogIn(),
            $request->boolean('remember'),
        );

        $ok = $ok || $this->attemptLegacy($credentials['email'], $credentials['password'], $request->boolean('remember'));

        if (! $ok) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        $request->session()->regenerate();
        Impersonation::forget();
        Activity::record('Logged in to the portal', $request->user('client'));

        return redirect()->intended(route('portal.dashboard'));
    }

    /**
     * Clients imported from WHMCS keep their old password: the first time it
     * matches, it is saved as a new hash and the old one is dropped.
     */
    private function attemptLegacy(string $email, string $password, bool $remember): bool
    {
        $client = Client::where('email', $email)->whereNotNull('legacy_password')->first();

        if ($client === null || ! $client->canLogIn() || ! LegacyPassword::verify($password, $client->legacy_password)) {
            return false;
        }

        $client->forceFill(['password' => $password])->save();
        Auth::guard('client')->login($client, $remember);
        Activity::record('Moved their WHMCS password to a new hash', $client);

        return true;
    }

    public function destroy(Request $request): RedirectResponse
    {
        // Staff signed in as this client: leave the client account, keep the staff login.
        if (Impersonation::active()) {
            $client = Impersonation::stop();

            return redirect()->route('admin.clients.show', $client);
        }

        Activity::record('Logged out of the portal', $request->user('client'));
        Auth::guard('client')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }
}
