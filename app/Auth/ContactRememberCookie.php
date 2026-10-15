<?php

namespace App\Auth;

use App\Models\ClientContact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Remember-me for contacts. The client guard's own remember-me cookie would
 * come back as the account holder, so contacts get this one: the contact,
 * their login's remember token, and a hash of their password, so a new
 * password or signing out ends it.
 */
class ContactRememberCookie
{
    public const NAME = 'portal_contact_remember';

    /** As long as the client guard's remember-me: about 400 days. */
    private const MINUTES = 576000;

    public static function queue(ClientContact $contact): void
    {
        if (blank($contact->remember_token)) {
            self::cycle($contact);
        }

        Cookie::queue(self::NAME, implode('|', [$contact->id, $contact->remember_token, self::passwordHash($contact)]), self::MINUTES);
    }

    /** Points the cookie at another of the login's accounts, when there is one. */
    public static function switchTo(Request $request, ClientContact $contact): void
    {
        if ($request->cookies->has(self::NAME)) {
            self::queue($contact);
        }
    }

    public static function forget(): void
    {
        Cookie::queue(Cookie::forget(self::NAME));
    }

    /** A new token, which ends every remembered login of this person. */
    public static function cycle(ClientContact $contact): void
    {
        $contact->forceFill(['remember_token' => Str::random(60)])->save();
    }

    /** The contact the cookie remembers, while their login and account still work. */
    public static function recall(Request $request): ?ClientContact
    {
        $parts = explode('|', (string) $request->cookies->get(self::NAME), 3);

        if (count($parts) !== 3 || ! ctype_digit($parts[0])) {
            return null;
        }

        $contact = ClientContact::with('client')->where('portal_access', true)->find((int) $parts[0]);

        $valid = $contact !== null
            && filled($contact->remember_token)
            && hash_equals((string) $contact->remember_token, $parts[1])
            && hash_equals(self::passwordHash($contact), $parts[2])
            && $contact->client->canLogIn();

        return $valid ? $contact : null;
    }

    private static function passwordHash(ClientContact $contact): string
    {
        return hash_hmac('sha256', (string) $contact->password, (string) config('app.key'));
    }
}
