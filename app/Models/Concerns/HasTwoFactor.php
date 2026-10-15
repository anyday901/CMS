<?php

namespace App\Models\Concerns;

use App\Auth\Totp;

/**
 * Two-factor authentication with an authenticator app, and one-time
 * recovery codes for when the phone is lost. For clients, contacts and staff.
 */
trait HasTwoFactor
{
    public const RECOVERY_CODES = 8;

    public function initializeHasTwoFactor(): void
    {
        $this->mergeCasts([
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_step' => 'integer',
        ]);
        $this->makeHidden(['two_factor_secret', 'two_factor_recovery_codes']);
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && filled($this->two_factor_secret);
    }

    /**
     * Turns two-factor on once the first code from the app checks out.
     *
     * @return list<string>|null the recovery codes, or null when the code is wrong
     */
    public function enableTwoFactor(string $secret, string $code): ?array
    {
        $step = Totp::verify($secret, self::normalizeCode($code));

        if ($step === null) {
            return null;
        }

        $codes = self::newRecoveryCodes();
        $this->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $step,
        ])->save();

        return $codes;
    }

    public function disableTwoFactor(): void
    {
        $this->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(): array
    {
        $codes = self::newRecoveryCodes();
        $this->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return $codes;
    }

    public function recoveryCodesLeft(): int
    {
        return count($this->two_factor_recovery_codes ?? []);
    }

    /** Checks a code from the app, or uses up a recovery code. */
    public function verifyTwoFactor(string $code): bool
    {
        if (! $this->hasTwoFactor()) {
            return false;
        }

        $code = self::normalizeCode($code);
        $step = Totp::verify($this->two_factor_secret, $code, $this->two_factor_last_step);

        if ($step !== null) {
            $this->forceFill(['two_factor_last_step' => $step])->save();

            return true;
        }

        return $this->useRecoveryCode($code);
    }

    /** Uses up a recovery code, so it works once. */
    private function useRecoveryCode(string $code): bool
    {
        $codes = $this->two_factor_recovery_codes ?? [];
        $used = null;
        foreach ($codes as $i => $recovery) {
            if (hash_equals($recovery, strtolower($code))) {
                $used = $i;
            }
        }

        if ($used === null) {
            return false;
        }

        unset($codes[$used]);
        $this->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

        return true;
    }

    /** @return list<string> */
    private static function newRecoveryCodes(): array
    {
        return array_map(
            fn () => implode('-', str_split(bin2hex(random_bytes(5)), 5)),
            range(1, self::RECOVERY_CODES),
        );
    }

    private static function normalizeCode(string $code): string
    {
        return preg_replace('/\s+/', '', trim($code));
    }
}
