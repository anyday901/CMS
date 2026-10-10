<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

class TaxRule extends Model
{
    protected $fillable = ['name', 'rate', 'country', 'state'];

    protected function casts(): array
    {
        return ['rate' => 'integer'];
    }

    /** The rule that taxes this client, or null when they are tax exempt or no rule matches. */
    public static function forClient(Client $client): ?self
    {
        if ($client->tax_exempt) {
            return null;
        }

        $country = strtoupper((string) $client->country);
        $state = strtolower(trim((string) $client->state));

        // The most specific match wins: country and state, then country, then everywhere.
        return static::all()
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

    /** Tax on an amount at a rate in hundredths of a percent, rounded half away from zero. */
    public static function taxOn(int $amount, int $rate): int
    {
        $raw = $amount * $rate;
        $tax = intdiv(abs($raw) + 5000, 10000);

        return $raw < 0 ? -$tax : $tax;
    }
}
