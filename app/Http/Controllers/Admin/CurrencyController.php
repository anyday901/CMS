<?php

namespace App\Http\Controllers\Admin;

use App\Billing\ExchangeRates;
use App\Billing\PriceConverter;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Currency;
use App\Models\ProductOptionChoice;
use App\Models\ProductPrice;
use App\Models\TldPrice;
use App\Support\Settings;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * Settings > Currencies: the currencies clients can be billed in, the
 * default one, and exchange rates against it, as in WHMCS.
 */
class CurrencyController extends Controller
{
    private const INDEX = 'admin.currencies.index';

    public function index(): View
    {
        return view('admin.currencies.index', [
            'currencies' => Currency::ordered(),
            'clientCounts' => DB::table('clients')->select('currency', DB::raw('count(*) as clients'))->groupBy('currency')->pluck('clients', 'currency'),
            'autoUpdate' => (bool) config('billing.update_exchange_rates'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'code' => ['required', 'regex:/^[A-Z]{3}$/', Rule::unique('currencies', 'code')],
            'rate' => ['nullable', 'string', 'max:32'],
        ], ['code.regex' => 'Enter a three-letter ISO 4217 code, such as EUR.', 'code.unique' => 'That currency is already here.']);

        $currency = Currency::create(['code' => $data['code'], 'rate' => $this->rate($data['rate'] ?? '1')]);
        Activity::record("Added currency {$currency->code}", $currency, ['rate' => $currency->rate]);

        return redirect()->route(self::INDEX)->with('status', "{$currency->code} added. Set its prices, or use Update product prices to convert them from ".Currency::defaultCode().'.');
    }

    public function edit(Currency $currency): View
    {
        return view('admin.currencies.edit', ['currency' => $currency]);
    }

    public function update(Request $request, Currency $currency): RedirectResponse
    {
        if ($currency->is_default) {
            return redirect()->route(self::INDEX)->withErrors(['rate' => "{$currency->code} is the default currency, so its rate is always 1."]);
        }

        $data = $request->validate(['rate' => ['required', 'string', 'max:32']]);
        $currency->update(['rate' => $this->rate($data['rate']), 'rate_updated_at' => now()]);
        Activity::record("Updated the {$currency->code} exchange rate", $currency, ['rate' => $currency->rate]);

        return redirect()->route(self::INDEX)->with('status', "{$currency->code} rate saved.");
    }

    /** Removes a currency nobody is billed in, and its prices. */
    public function destroy(Currency $currency): RedirectResponse
    {
        $problem = match (true) {
            $currency->is_default => "{$currency->code} is the default currency. Make another currency the default first.",
            $currency->inUse() => "Clients, invoices or payments are in {$currency->code}, so it can't be deleted.",
            default => null,
        };
        if ($problem !== null) {
            return back()->withErrors(['currency' => $problem]);
        }

        DB::transaction(function () use ($currency) {
            ProductPrice::where('currency', $currency->code)->delete();
            TldPrice::where('currency', $currency->code)->delete();
            ProductOptionChoice::whereNotNull('currency_prices')->get()->each(function (ProductOptionChoice $choice) use ($currency) {
                $prices = (array) $choice->currency_prices;
                unset($prices[$currency->code]);
                $choice->update(['currency_prices' => $prices ?: null]);
            });
            $currency->delete();
        });
        Activity::record("Deleted currency {$currency->code} and its prices");

        return redirect()->route(self::INDEX)->with('status', "{$currency->code} deleted.");
    }

    /**
     * Makes a currency the default. Every rate is restated against it, and
     * configurable option prices move so their main prices are in it.
     * Clients keep the currency they have.
     */
    public function makeDefault(Currency $currency): RedirectResponse
    {
        if ($currency->is_default) {
            return redirect()->route(self::INDEX);
        }

        $old = Currency::defaultCode();
        DB::transaction(function () use ($currency, $old) {
            $this->moveOptionPrices($old, $currency->code);

            $base = BigDecimal::of($currency->rate);
            foreach (Currency::all() as $other) {
                $rate = match (true) {
                    $other->is($currency) => '1',
                    $other->is_default => Currency::plain(BigDecimal::one()->dividedBy($base, Currency::RATE_SCALE, RoundingMode::HalfUp)),
                    default => Currency::plain(BigDecimal::of($other->rate)->dividedBy($base, Currency::RATE_SCALE, RoundingMode::HalfUp)),
                };
                $other->update(['rate' => $rate, 'is_default' => $other->is($currency)]);
            }

            Settings::save(['billing.currency' => $currency->code]);
        });
        Currency::flush();
        Activity::record("Made {$currency->code} the default currency", $currency, ['was' => $old]);

        return redirect()->route(self::INDEX)->with('status', "{$currency->code} is now the default currency. New clients get it, and the other rates are against it.");
    }

    public function updateRates(ExchangeRates $rates): RedirectResponse
    {
        try {
            $result = $rates->update();
        } catch (RuntimeException $e) {
            return redirect()->route(self::INDEX)->withErrors(['rates' => $e->getMessage()]);
        }

        $message = count($result['updated']) === 1 ? 'Updated 1 exchange rate' : 'Updated '.count($result['updated']).' exchange rates';
        $message .= $result['date'] ? " from the European Central Bank's rates for {$result['date']}." : '.';
        if ($result['missing'] !== []) {
            $message .= ' The European Central Bank has no rate for '.implode(', ', $result['missing']).', so '.(count($result['missing']) === 1 ? 'it keeps its' : 'they keep their').' current rate.';
        }

        return redirect()->route(self::INDEX)->with('status', $message);
    }

    public function updatePrices(PriceConverter $converter): RedirectResponse
    {
        $counts = $converter->run();

        return redirect()->route(self::INDEX)->with('status', 'Converted from '.Currency::defaultCode().": {$counts['products']} product prices, {$counts['options']} option prices and {$counts['tlds']} TLD prices.");
    }

    /**
     * Swaps configurable option prices between their main columns (the
     * default currency) and the other currencies. A choice without prices
     * in the new default gets them converted from the old one.
     */
    private function moveOptionPrices(string $old, string $new): void
    {
        foreach (ProductOptionChoice::all() as $choice) {
            $others = (array) ($choice->currency_prices ?? []);
            $main = $others[$new] ?? [
                'prices' => array_map(fn ($cents) => Currency::convert((int) $cents, $old, $new), (array) ($choice->prices ?? [])),
                'setup_fee' => Currency::convert((int) $choice->setup_fee, $old, $new),
            ];
            unset($others[$new]);
            $others[$old] = ['prices' => (array) ($choice->prices ?? []), 'setup_fee' => (int) $choice->setup_fee];

            $choice->update(['prices' => $main['prices'] ?: null, 'setup_fee' => (int) ($main['setup_fee'] ?? 0), 'currency_prices' => $others]);
        }
    }

    /** @throws ValidationException when it isn't a positive number */
    private function rate(string $input): string
    {
        return Currency::normaliseRate($input)
            ?? throw ValidationException::withMessages(['rate' => 'Enter the rate as a positive number, such as 0.92: how many of this currency make one '.Currency::defaultCode().'.']);
    }
}
