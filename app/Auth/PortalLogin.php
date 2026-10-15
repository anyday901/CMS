<?php

namespace App\Auth;

use App\Models\Activity;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\LoginAttempt;
use App\Security\LoginSecurity;
use App\Security\NewDeviceAlert;
use App\Support\Impersonation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Portal logins: the account holder with the client's email, or a contact
 * with portal access with their own. A contact is signed in to the account
 * they belong to, as that contact; a contact on several accounts lands on
 * the one they used last and can switch.
 */
class PortalLogin
{
    public function __construct(private LoginSecurity $security, private NewDeviceAlert $devices) {}

    /** The client or contact these credentials are for, without signing in. */
    public function find(string $email, string $password): Client|ClientContact|null
    {
        $client = Client::where('email', $email)->first();

        if ($client !== null) {
            if (! $client->canLogIn()) {
                return null;
            }

            return LoginSecurity::passwordMatches($client, $password) || $this->checkLegacy($client, $password) ? $client : null;
        }

        // The login is the same on every account, so the first account that can sign in decides.
        foreach ($this->contacts($email) as $contact) {
            if ($contact->client->canLogIn()) {
                return LoginSecurity::passwordMatches($contact, $password) ? $contact : null;
            }
        }

        return null;
    }

    /** Whether the account can still sign in, such as between the password and the two-factor code. */
    public function canLogIn(Client|ClientContact $account): bool
    {
        return $account instanceof ClientContact
            ? $account->portal_access && $account->client->canLogIn()
            : $account->canLogIn();
    }

    /** Whose account the email is, for the login history of a failed login. */
    public function owner(string $email): Client|ClientContact|null
    {
        return Client::where('email', $email)->first() ?? $this->contact($email);
    }

    public function complete(Request $request, Client|ClientContact $account, bool $remember): void
    {
        $request->session()->regenerate();
        Impersonation::forget();
        PortalContact::forget();

        if ($account instanceof ClientContact) {
            // The client guard's remember-me would sign in the account holder, so contacts have their own.
            Auth::guard('client')->login($account->client);
            PortalContact::start($account);
            $account->forceFill(['last_login_at' => now()])->save();
            $remember ? ContactRememberCookie::queue($account) : ContactRememberCookie::forget();
        } else {
            Auth::guard('client')->login($account, $remember);
            ContactRememberCookie::forget();
        }

        $this->security->succeeded(LoginAttempt::PORTAL, $account, $request);
        Activity::record('Logged in to the portal', $account instanceof ClientContact ? $account->client : $account);
        $this->devices->check($request, $account);
    }

    /** Where to go after logging in: a contact on several accounts picks one first. */
    public static function home(Client|ClientContact $account): RedirectResponse
    {
        return $account instanceof ClientContact && $account->hasOtherAccounts()
            ? redirect()->route('portal.accounts')
            : redirect()->intended(route('portal.dashboard'));
    }

    /**
     * Signs out of the portal. A contact leaves without signing the account
     * holder out of their other devices, and their own remembered logins end.
     */
    public static function logout(Request $request): void
    {
        if (PortalContact::signedIn()) {
            $contact = PortalContact::current();
            if ($contact !== null && $request->cookies->has(ContactRememberCookie::NAME)) {
                ContactRememberCookie::cycle($contact);
            }
            PortalContact::forget();
            Auth::guard('client')->logoutCurrentDevice();
        } else {
            Auth::guard('client')->logout();
        }

        ContactRememberCookie::forget();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function contact(string $email): ?ClientContact
    {
        return $this->contacts($email)->first();
    }

    /**
     * The login's contacts, the account used most recently first.
     *
     * @return Collection<int, ClientContact>
     */
    private function contacts(string $email): Collection
    {
        return ClientContact::loginsFor($email)->with('client')
            ->orderByRaw('last_login_at is null')->orderByDesc('last_login_at')->orderBy('id')
            ->get();
    }

    /**
     * Clients imported from WHMCS keep their old password: the first time it
     * matches, it is saved as a new hash and the old one is dropped.
     */
    private function checkLegacy(Client $client, string $password): bool
    {
        if ($client->legacy_password === null || ! LegacyPassword::verify($password, $client->legacy_password)) {
            return false;
        }

        $client->forceFill(['password' => $password])->save();
        Activity::record('Moved their WHMCS password to a new hash', $client);

        return true;
    }
}
