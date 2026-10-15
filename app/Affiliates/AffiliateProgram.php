<?php

namespace App\Affiliates;

use App\Models\Affiliate;
use App\Support\Money;
use InvalidArgumentException;

/** The affiliate program's settings (config/affiliates.php, changed on the admin Affiliates settings page), read in one place. */
class AffiliateProgram
{
    public function enabled(): bool
    {
        return (bool) config('affiliates.enabled');
    }

    public function selfSignUp(): bool
    {
        return (bool) config('affiliates.self_signup');
    }

    /**
     * The program's commission: percent in hundredths of a percent (1000 is
     * 10%), or a fixed amount in minor units.
     *
     * @return array{0: string, 1: int}
     */
    public function commissionRate(): array
    {
        $type = config('affiliates.commission_type') === Affiliate::FIXED ? Affiliate::FIXED : Affiliate::PERCENT;

        return [$type, self::parse((string) config('affiliates.commission_amount'))];
    }

    public function firstInvoiceOnly(): bool
    {
        return (bool) config('affiliates.first_invoice_only');
    }

    /** Months after a referral that its invoices still earn commission, or null for no limit. */
    public function months(): ?int
    {
        $months = config('affiliates.months');

        return filled($months) && (int) $months > 0 ? (int) $months : null;
    }

    public function clearingDays(): int
    {
        return max(0, (int) config('affiliates.clearing_days'));
    }

    /** In minor units, in whichever currency the balance is in. */
    public function minimumPayout(): int
    {
        return self::parse((string) config('affiliates.minimum_payout'));
    }

    public function cookieDays(): int
    {
        return max(1, (int) config('affiliates.cookie_days'));
    }

    /**
     * What a commission of this type and value comes to on an amount
     * before tax: a percent rounded half up, or the fixed amount.
     */
    public static function commission(string $type, int $value, int $amount): int
    {
        if ($amount <= 0) {
            return 0;
        }

        return $type === Affiliate::FIXED ? $value : intdiv($amount * $value * 2 + 10000, 20000);
    }

    /** "10", "5.00" to hundredths or cents; anything unreadable is 0. */
    private static function parse(string $value): int
    {
        try {
            return max(0, Money::parse($value === '' ? '0' : $value));
        } catch (InvalidArgumentException) {
            return 0;
        }
    }
}
