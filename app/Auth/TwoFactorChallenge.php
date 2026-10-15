<?php

namespace App\Auth;

use Illuminate\Database\Eloquent\Model;

/**
 * A login waiting for its two-factor code. The password was right; nobody
 * is signed in until the code is too. Kept in the session for ten minutes
 * and five wrong codes.
 */
class TwoFactorChallenge
{
    private const KEY = 'two_factor_login';

    private const MINUTES = 10;

    public const MAX_TRIES = 5;

    public static function start(string $area, Model $account, bool $remember): void
    {
        session()->put(self::KEY, [
            'area' => $area,
            'type' => $account::class,
            'id' => $account->getKey(),
            'remember' => $remember,
            'tries' => 0,
            'expires' => now()->addMinutes(self::MINUTES)->getTimestamp(),
        ]);
    }

    /** The account waiting for its code in this area, or null when there's none (or it expired). */
    public static function pending(string $area): ?Model
    {
        $pending = session(self::KEY);

        if (! is_array($pending) || $pending['area'] !== $area || $pending['expires'] < time()) {
            return null;
        }

        return $pending['type']::find($pending['id']);
    }

    public static function remember(): bool
    {
        return (bool) (session(self::KEY)['remember'] ?? false);
    }

    /** Counts a wrong code. Returns false once there have been too many, and drops the login. */
    public static function failed(): bool
    {
        $pending = session(self::KEY);
        $pending['tries']++;

        if ($pending['tries'] >= self::MAX_TRIES) {
            self::clear();

            return false;
        }

        session()->put(self::KEY, $pending);

        return true;
    }

    public static function clear(): void
    {
        session()->forget(self::KEY);
    }
}
