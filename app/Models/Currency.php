<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * A currency clients can be billed in. Its rate is how many of it make one
 * of the default currency, as a decimal string; the default's rate is 1.
 * Amounts are converted with exact decimal arithmetic and rounded half up
 * to the cent.
 */
class Currency extends Model
{
    /** Decimal places a rate is kept to. */
    public const RATE_SCALE = 10;

    protected $fillable = ['code', 'rate', 'is_default', 'rate_updated_at'];

    /** Where the rates read for this request are kept in the container. */
    private const CACHE = 'currencies.rates';

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'rate_updated_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::flush());
        static::deleted(fn () => self::flush());
    }

    /** @return Collection<int, self> The default first, then by code. */
    public static function ordered(): Collection
    {
        return self::orderByDesc('is_default')->orderBy('code')->get();
    }

    /** @return list<string> Every currency code, the default first. */
    public static function codes(): array
    {
        return array_keys(self::rates());
    }

    public static function known(string $code): bool
    {
        return array_key_exists(strtoupper($code), self::rates());
    }

    public static function defaultCode(): string
    {
        return (string) config('billing.currency');
    }

    /** Clients, invoices or payments in this currency stop it being deleted. */
    public function inUse(): bool
    {
        return Client::where('currency', $this->code)->exists()
            || Invoice::where('currency', $this->code)->exists()
            || Transaction::where('currency', $this->code)->exists();
    }

    /**
     * $cents in $from as $to, through the default currency, rounded half up
     * to the cent. A currency missing from the table counts at a rate of 1.
     */
    public static function convert(int $cents, string $from, string $to): int
    {
        if ($cents === 0 || $from === $to) {
            return $cents;
        }

        $rates = self::rates();
        $fromRate = BigDecimal::of($rates[$from] ?? '1');
        $toRate = BigDecimal::of($rates[$to] ?? '1');

        if ($fromRate->isZero()) {
            throw new InvalidArgumentException("{$from} has no exchange rate.");
        }

        return BigDecimal::of($cents)->multipliedBy($toRate)->dividedBy($fromRate, 0, RoundingMode::HalfUp)->toInt();
    }

    /** $cents in $from as the default currency. */
    public static function toDefault(int $cents, string $from): int
    {
        return self::convert($cents, $from, self::defaultCode());
    }

    /**
     * Adds up amounts in several currencies as the default currency. An
     * amount in a currency that isn't in the table has no rate, so it is
     * left out rather than guessed at.
     *
     * @param  iterable<string, int|string|null>  $totals  currency => cents
     */
    public static function sumInDefault(iterable $totals): int
    {
        $sum = 0;
        foreach ($totals as $currency => $cents) {
            if (self::known((string) $currency)) {
                $sum += self::toDefault((int) $cents, (string) $currency);
            }
        }

        return $sum;
    }

    /**
     * A rate as typed, such as "1.0825" or "0,92", as a normalised decimal
     * string, or null when it isn't a positive number.
     */
    public static function normaliseRate(string $rate): ?string
    {
        $rate = str_replace(',', '.', trim($rate));

        if (! preg_match('/^\d{1,12}(\.\d+)?$/', $rate)) {
            return null;
        }

        $decimal = BigDecimal::of($rate)->toScale(self::RATE_SCALE, RoundingMode::HalfUp);

        return $decimal->isPositive() ? self::plain($decimal) : null;
    }

    /** A decimal without trailing zeros, such as "1.5" or "2". */
    public static function plain(BigDecimal $decimal): string
    {
        $text = (string) $decimal;

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /** @return array<string, string> code => rate, the default first. */
    private static function rates(): array
    {
        if (! app()->bound(self::CACHE)) {
            app()->instance(self::CACHE, self::ordered()->mapWithKeys(fn (self $currency) => [$currency->code => $currency->is_default ? '1' : $currency->rate])->all());
        }

        return app(self::CACHE);
    }

    /** Forgets the rates read for this request, after they change elsewhere. */
    public static function flush(): void
    {
        app()->forgetInstance(self::CACHE);
    }
}
