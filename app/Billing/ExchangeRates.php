<?php

namespace App\Billing;

use App\Models\Activity;
use App\Models\Currency;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

/**
 * Updates every currency's exchange rate from the European Central Bank's
 * daily reference rates. The ECB quotes each currency against the euro, so
 * each rate is crossed to the default currency: (currency per euro) divided
 * by (default per euro). Currencies the ECB doesn't quote keep their rate.
 */
class ExchangeRates
{
    public const FEED = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    /**
     * @return array{date: ?string, updated: array<string, string>, missing: list<string>}
     *
     * @throws ExchangeRatesUnavailable when the feed can't be read or doesn't quote the default currency.
     */
    public function update(): array
    {
        ['date' => $date, 'rates' => $perEuro] = $this->fetch();
        $default = Currency::defaultCode();

        if (! isset($perEuro[$default])) {
            throw new ExchangeRatesUnavailable("The European Central Bank doesn't publish a rate for {$default}, the default currency, so no rates were changed.");
        }

        $updated = [];
        $missing = [];
        $base = BigDecimal::of($perEuro[$default]);

        DB::transaction(function () use ($perEuro, $base, &$updated, &$missing) {
            foreach (Currency::where('is_default', false)->orderBy('code')->get() as $currency) {
                if (! isset($perEuro[$currency->code])) {
                    $missing[] = $currency->code;

                    continue;
                }

                $rate = Currency::plain(BigDecimal::of($perEuro[$currency->code])->dividedBy($base, Currency::RATE_SCALE, RoundingMode::HalfUp));
                $currency->update(['rate' => $rate, 'rate_updated_at' => now()]);
                $updated[$currency->code] = $rate;
            }
        });

        Activity::record('Updated exchange rates from the European Central Bank', null, array_filter(['date' => $date, 'rates' => $updated, 'missing' => $missing]));

        return ['date' => $date, 'updated' => $updated, 'missing' => $missing];
    }

    /**
     * The feed's rates as currency => units per euro, with the euro itself at 1.
     *
     * @return array{date: ?string, rates: array<string, string>}
     */
    private function fetch(): array
    {
        try {
            $response = Http::timeout(20)->retry(2, 1000, throw: false)->get(self::FEED);
        } catch (Throwable $e) {
            throw new ExchangeRatesUnavailable("Couldn't reach the European Central Bank: {$e->getMessage()}", previous: $e);
        }

        if (! $response->successful()) {
            throw new ExchangeRatesUnavailable("The European Central Bank answered with HTTP {$response->status()}.");
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($response->body(), SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_use_internal_errors($previous);
        }

        if ($xml === false) {
            throw new ExchangeRatesUnavailable("The European Central Bank's rates couldn't be read.");
        }

        $rates = ['EUR' => '1'];
        $date = null;
        // <Cube><Cube time="..."><Cube currency="USD" rate="1.0825"/>...
        foreach ($xml->xpath('//*[local-name()="Cube"][@currency][@rate]') ?: [] as $cube) {
            $code = strtoupper((string) $cube['currency']);
            $rate = (string) $cube['rate'];
            if (preg_match('/^[A-Z]{3}$/', $code) && Currency::normaliseRate($rate) !== null) {
                $rates[$code] = $rate;
            }
        }
        foreach ($xml->xpath('//*[local-name()="Cube"][@time]') ?: [] as $cube) {
            $date = (string) $cube['time'];
        }

        if (count($rates) === 1) {
            throw new ExchangeRatesUnavailable("The European Central Bank's feed had no rates in it.");
        }

        return ['date' => $date, 'rates' => $rates];
    }
}
