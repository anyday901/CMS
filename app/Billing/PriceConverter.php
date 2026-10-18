<?php

namespace App\Billing;

use App\Models\Activity;
use App\Models\Currency;
use App\Models\ProductOptionChoice;
use App\Models\ProductPrice;
use App\Models\TldPrice;
use Illuminate\Support\Facades\DB;

/**
 * Sets every product, configurable option and TLD price in the other
 * currencies from its price in the default currency, at today's rates, as
 * WHMCS's "Update Product Prices" does. Prices only in another currency are
 * left as they are, and nothing is deleted.
 */
class PriceConverter
{
    /** @return array{products: int, options: int, tlds: int} How many prices were set. */
    public function run(): array
    {
        $default = Currency::defaultCode();
        $others = array_values(array_diff(Currency::codes(), [$default]));
        $counts = ['products' => 0, 'options' => 0, 'tlds' => 0];

        if ($others === []) {
            return $counts;
        }

        DB::transaction(function () use ($default, $others, &$counts) {
            $counts['products'] = $this->products($default, $others);
            $counts['options'] = $this->options($default, $others);
            $counts['tlds'] = $this->tlds($default, $others);
        });

        Activity::record('Updated prices in other currencies from the default currency', null, ['currencies' => $others] + $counts);

        return $counts;
    }

    /** @param  list<string>  $others */
    private function products(string $default, array $others): int
    {
        $count = 0;

        foreach (ProductPrice::where('currency', $default)->get() as $price) {
            foreach ($others as $currency) {
                ProductPrice::updateOrCreate(
                    ['product_id' => $price->product_id, 'billing_cycle' => $price->billing_cycle, 'currency' => $currency],
                    ['price' => Currency::convert($price->price, $default, $currency), 'setup_fee' => Currency::convert($price->setup_fee, $default, $currency)],
                );
                $count++;
            }
        }

        return $count;
    }

    /** @param  list<string>  $others */
    private function options(string $default, array $others): int
    {
        $count = 0;

        foreach (ProductOptionChoice::all() as $choice) {
            $prices = (array) ($choice->currency_prices ?? []);
            foreach ($others as $currency) {
                $prices[$currency] = [
                    'prices' => array_map(fn ($cents) => Currency::convert((int) $cents, $default, $currency), (array) ($choice->prices ?? [])),
                    'setup_fee' => Currency::convert((int) $choice->setup_fee, $default, $currency),
                ];
                $count++;
            }
            $choice->update(['currency_prices' => $prices]);
        }

        return $count;
    }

    /** @param  list<string>  $others */
    private function tlds(string $default, array $others): int
    {
        $count = 0;

        foreach (TldPrice::where('currency', $default)->get() as $price) {
            foreach ($others as $currency) {
                $convert = fn (?int $cents) => $cents === null ? null : Currency::convert($cents, $default, $currency);
                TldPrice::updateOrCreate(['tld_id' => $price->tld_id, 'currency' => $currency, 'years' => $price->years], [
                    'register' => $convert($price->register),
                    'transfer' => $convert($price->transfer),
                    'renew' => $convert($price->renew),
                    'id_protection' => (int) $convert((int) $price->id_protection),
                ]);
                $count++;
            }
        }

        return $count;
    }
}
