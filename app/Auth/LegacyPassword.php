<?php

namespace App\Auth;

/**
 * Checks a password against a hash imported from WHMCS. WHMCS has used
 * bcrypt since 5.3.9 and moves older hashes to it at login, so only modern
 * hashes are kept. Clients whose WHMCS hash was older reset their password
 * by email instead.
 */
class LegacyPassword
{
    /** Whether a WHMCS hash is one this app can check (bcrypt or Argon2). */
    public static function supported(?string $hash): bool
    {
        return in_array(password_get_info(trim((string) $hash))['algoName'], ['bcrypt', 'argon2i', 'argon2id'], true);
    }

    public static function verify(string $password, ?string $hash): bool
    {
        return $password !== '' && self::supported($hash) && password_verify($password, trim((string) $hash));
    }
}
