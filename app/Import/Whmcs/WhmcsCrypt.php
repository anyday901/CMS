<?php

namespace App\Import\Whmcs;

/**
 * WHMCS's own encryption of stored passwords (servers, services), keyed by
 * the cc_encryption_hash in its configuration.php. The key never leaves
 * WHMCS's files, so staff type it in to bring passwords over.
 */
final class WhmcsCrypt
{
    private readonly string $key;

    public function __construct(string $encryptionHash)
    {
        $this->key = self::digest(md5(md5($encryptionHash)).md5($encryptionHash));
    }

    /**
     * The plain text, or null when it doesn't decrypt to text, which is what
     * a wrong encryption hash gives.
     */
    public function decrypt(?string $value): ?string
    {
        $value = trim((string) $value);
        $data = $value === '' ? false : base64_decode($value, true);
        $length = strlen($this->key);

        if ($data === false || strlen($data) <= $length) {
            return null;
        }

        $chain = substr($data, 0, $length) ^ $this->key;
        $cipher = substr($data, $length);
        $plain = '';

        for ($i = 0, $n = strlen($cipher); $i < $n; $i++) {
            if ($i !== 0 && $i % $length === 0) {
                $chain = self::digest($chain.substr($plain, $i - $length, $length));
            }

            $plain .= $chain[$i % $length] ^ $cipher[$i];
        }

        return mb_check_encoding($plain, 'UTF-8') && ! preg_match('/[\x00-\x1F\x7F]/', $plain) ? $plain : null;
    }

    /** Encrypts as WHMCS does, for tests and tools that write WHMCS data. */
    public function encrypt(string $plain): string
    {
        $length = strlen($this->key);
        $chain = random_bytes($length);
        $out = $chain ^ $this->key;

        for ($i = 0, $n = strlen($plain); $i < $n; $i++) {
            if ($i !== 0 && $i % $length === 0) {
                $chain = self::digest($chain.substr($plain, $i - $length, $length));
            }

            $out .= $chain[$i % $length] ^ $plain[$i];
        }

        return base64_encode($out);
    }

    /** SHA-1 as raw bytes, as WHMCS's _hash() returns it. */
    private static function digest(string $value): string
    {
        return sha1($value, true);
    }
}
