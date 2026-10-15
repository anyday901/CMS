<?php

namespace App\Support;

use App\Auth\PortalContact;
use App\Models\Activity;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Staff signed in to the portal as a client, from the admin area. Both
 * guards share the session, so the staff login stays put underneath and
 * everything done in the portal meanwhile is logged under the staff member.
 */
class Impersonation
{
    private const KEY = 'impersonated_by';

    public static function start(User $staff, Client $client): void
    {
        PortalContact::forget();
        Auth::guard('client')->login($client);
        session()->regenerate();
        session()->put(self::KEY, $staff->id);
        Activity::record("Logged in to the portal as {$client->fullName()}", $client);
    }

    /** True while the staff member who started it is still signed in. */
    public static function active(): bool
    {
        $staffId = session(self::KEY);

        return $staffId !== null && Auth::guard('client')->check() && Auth::guard('web')->id() === $staffId;
    }

    /** Signs out of the client account only, and returns that client so staff can go back to them. */
    public static function stop(): ?Client
    {
        $client = Auth::guard('client')->user();

        if ($client !== null) {
            Activity::record("Left the portal as {$client->fullName()}", $client);
        }

        // Not logout(): that rotates the client's remember token and signs them out on their own devices.
        Auth::guard('client')->logoutCurrentDevice();
        PortalContact::forget();
        session()->forget(self::KEY);
        session()->regenerate();

        return $client;
    }

    /** Called on a normal portal login, so an old flag can't outlive the session it belonged to. */
    public static function forget(): void
    {
        session()->forget(self::KEY);
    }
}
