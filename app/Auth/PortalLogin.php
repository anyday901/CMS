<?php

namespace App\Auth;

use App\Models\Activity;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\LoginAttempt;
use App\Security\LoginSecurity;
use App\Support\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Portal logins: the account holder with the client's email, or a contact
 * with portal access with their own. A contact is signed in to the account
 * they belong to, as that contact.
 */
class PortalLogin
{
    public function __construct(private LoginSecurity $security) {}

    /** The client or contact these credentials are for, without signing in. */
    public function find(string $email, string $password): Client|ClientContact|null
    {
        $client = Client::where('email', $email)->first();

        if ($client !== null) {
            if (! $client->canLogIn()) {
                return null;
            }

            return Hash::check($password, (string) $client->password) || $this->checkLegacy($client, $password) ? $client : null;
        }

        $contact = $this->contact($email);

        return $contact !== null && $contact->client->canLogIn() && Hash::check($password, (string) $contact->password) ? $contact : null;
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
            // No remember-me for contacts: the cookie would sign in the account holder.
            Auth::guard('client')->login($account->client);
            PortalContact::start($account);
            $account->forceFill(['last_login_at' => now()])->save();
        } else {
            Auth::guard('client')->login($account, $remember);
        }

        $this->security->succeeded(LoginAttempt::PORTAL, $account, $request);
        Activity::record('Logged in to the portal', $account instanceof ClientContact ? $account->client : $account);
    }

    /** Signs out of the portal. A contact leaves without signing the account holder out of their other devices. */
    public static function logout(Request $request): void
    {
        if (PortalContact::signedIn()) {
            PortalContact::forget();
            Auth::guard('client')->logoutCurrentDevice();
        } else {
            Auth::guard('client')->logout();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function contact(string $email): ?ClientContact
    {
        return ClientContact::with('client')->where('email', $email)->where('portal_access', true)->first();
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
