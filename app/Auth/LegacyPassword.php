<?php

namespace App\Auth;

/**
 * Checks a password against a hash imported from WHMCS. Newer WHMCS installs
 * use bcrypt; older ones stored md5(salt + password) as "hash:salt", or a
 * bare md5. Unknown formats never match, so those clients reset their
 * password by email instead.
 */
class LegacyPassword
{
    public static function verify(string $password, ?string $hash): bool
    {
        $hash = trim((string) $hash);

        if ($hash === '' || $password === '') {
            return false;
        }

        if (str_starts_with($hash, '$')) {
            return password_verify($password, $hash);
        }

        if (preg_match('/^([a-f0-9]{32}):(.+)$/i', $hash, $m)) {
            return hash_equals(strtolower($m[1]), md5($m[2].$password));
        }

        if (preg_match('/^[a-f0-9]{32}$/i', $hash)) {
            return hash_equals(strtolower($hash), md5($password));
        }

        return false;
    }
}
