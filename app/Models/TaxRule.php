<?php

namespace App\Models;

use App\Billing\VatNumbers;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

class TaxRule extends Model
{
    protected $fillable = ['name', 'rate', 'level', 'country', 'state'];

    protected $attributes = ['level' => 1];

    protected function casts(): array
    {
        return ['rate' => 'integer', 'level' => 'integer'];
    }

    /**
     * The rule of this level that taxes this client, or null when they are
     * tax exempt, reverse charged or no rule matches.
     */
    public static function forClient(Client $client, int $level = 1): ?self
    {
        if ($client->tax_exempt || VatNumbers::reverseCharged($client)) {
            return null;
        }

        $country = strtoupper((string) $client->country);
        $state = strtolower(trim((string) $client->state));

        // The most specific match wins: country and state, then country, then everywhere.
        return static::where('level', $level)->get()
            ->filter(fn (self $rule) => ($rule->country === null || $rule->country === $country)
                && ($rule->state === null || strtolower($rule->state) === $state))
            ->sortByDesc(fn (self $rule) => ($rule->state !== null ? 2 : 0) + ($rule->country !== null ? 1 : 0))
            ->first();
    }

    /** "8.25%" */
    public function rateLabel(): string
    {
        return self::formatRate($this->rate);
    }

    public static function formatRate(int $rate): string
    {
        return rtrim(rtrim(Money::toInput($rate), '0'), '.').'%';
    }

    /** The tax already inside a tax-inclusive amount, rounded half away from zero. */
    public static function taxIncludedIn(int $amount, int $rate): int
    {
        $divisor = 10000 + $rate;
        $net = intdiv(abs($amount) * 20000 + $divisor, 2 * $divisor);
        $tax = abs($amount) - $net;

        return $amount < 0 ? -$tax : $tax;
    }

    /** Tax on an amount at a rate in hundredths of a percent, rounded half away from zero. */
    public static function taxOn(int $amount, int $rate): int
    {
        $raw = $amount * $rate;
        $tax = intdiv(abs($raw) + 5000, 10000);

        return $raw < 0 ? -$tax : $tax;
    }

    /**
     * The level 1 and level 2 tax on a taxable amount. Compound level 2 tax
     * is charged on the amount plus level 1 tax. With tax-inclusive pricing
     * the amount already holds both, and level 2 takes the rounding so the
     * two add up to exactly the tax inside it. With no level 2 rate this is
     * the same as taxOn() or taxIncludedIn() at the level 1 rate.
     *
     * @return array{0: int, 1: int}
     */
    public static function levels(int $amount, int $rate1, int $rate2 = 0, bool $compound = false, bool $inclusive = false): array
    {
        if (! $inclusive) {
            $tax1 = self::taxOn($amount, $rate1);

            return [$tax1, self::taxOn($amount + ($compound ? $tax1 : 0), $rate2)];
        }

        if ($rate2 === 0) {
            return [self::taxIncludedIn($amount, $rate1), 0];
        }

        if ($compound) {
            // net × (1 + rate1) × (1 + rate2) = amount, rounded half away from zero.
            $divisor = (10000 + $rate1) * (10000 + $rate2);
            $net = intdiv(abs($amount) * 200000000 + $divisor, 2 * $divisor);
            $net = $amount < 0 ? -$net : $net;
        } else {
            $net = $amount - self::taxIncludedIn($amount, $rate1 + $rate2);
        }

        $tax1 = self::taxOn($net, $rate1);

        return [$tax1, $amount - $net - $tax1];
    }
}
