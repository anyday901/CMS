<?php

namespace App\Auth;

use App\Models\Client;
use App\Models\ClientContact;
use Illuminate\Support\Facades\Auth;

/**
 * A contact signed in to the portal. The client guard holds the account
 * they belong to, so every portal page works on that account as usual; the
 * session says which contact it is, and their permissions decide which
 * pages they can open.
 */
class PortalContact
{
    private const KEY = 'portal_contact_id';

    private const REQUEST_KEY = 'portal_contact';

    /** Pages only the account holder can open, such as account details and contacts. */
    public const OWNER = 'owner';

    public static function start(ClientContact $contact): void
    {
        session()->put(self::KEY, $contact->id);
    }

    public static function forget(): void
    {
        session()->forget(self::KEY);
        request()->attributes->remove(self::REQUEST_KEY);
    }

    /** Whether the session belongs to a contact, even one who has since lost access. */
    public static function signedIn(): bool
    {
        return session()->has(self::KEY) && Auth::guard('client')->check();
    }

    /** The contact signed in, while they still have portal access to this account. */
    public static function current(): ?ClientContact
    {
        if (! self::signedIn()) {
            return null;
        }

        $request = request();
        if (! $request->attributes->has(self::REQUEST_KEY)) {
            $request->attributes->set(self::REQUEST_KEY, ClientContact::whereKey(session(self::KEY))
                ->where('client_id', Auth::guard('client')->id())
                ->where('portal_access', true)
                ->first());
        }

        return $request->attributes->get(self::REQUEST_KEY);
    }

    /** Whoever is signed in to the portal: the contact, or the account holder. */
    public static function account(): Client|ClientContact|null
    {
        return self::current() ?? Auth::guard('client')->user();
    }

    /** The account holder can do everything; a contact only what they're allowed. */
    public static function allows(string $permission): bool
    {
        if (! self::signedIn()) {
            return Auth::guard('client')->check();
        }

        return $permission !== self::OWNER && (bool) self::current()?->allows($permission);
    }

    /** The name to log and show for what's done in the portal. */
    public static function name(): ?string
    {
        $contact = self::current();

        return $contact ? "{$contact->fullName()} (contact)" : Auth::guard('client')->user()?->fullName();
    }
}
