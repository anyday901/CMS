<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One choice of a configurable option, with a price per billing cycle in
 * cents. prices and setup_fee are in the default currency; currency_prices
 * holds the other currencies as code => {prices, setup_fee}.
 */
class ProductOptionChoice extends Model
{
    protected $fillable = ['name', 'prices', 'setup_fee', 'currency_prices', 'sort_order'];

    protected function casts(): array
    {
        return ['prices' => 'array', 'setup_fee' => 'integer', 'currency_prices' => 'array', 'sort_order' => 'integer'];
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }

    /** The price for one unit on this cycle, in the default currency or $currency; a cycle without a price is free. */
    public function priceFor(BillingCycle $cycle, ?string $currency = null): int
    {
        $prices = self::isDefault($currency) ? $this->prices : ($this->currency_prices[$currency]['prices'] ?? []);

        return (int) ($prices[$cycle->value] ?? 0);
    }

    /** The setup fee for one unit, in the default currency or $currency. */
    public function setupFeeFor(?string $currency = null): int
    {
        return (int) (self::isDefault($currency) ? $this->setup_fee : ($this->currency_prices[$currency]['setup_fee'] ?? 0));
    }

    /**
     * Whether the choice can be sold in $currency: it has prices set in it,
     * or it is free in the default currency, so free everywhere.
     */
    public function pricedIn(string $currency): bool
    {
        return self::isDefault($currency)
            || isset($this->currency_prices[$currency])
            || ((int) $this->setup_fee === 0 && array_sum(array_map('intval', (array) ($this->prices ?? []))) === 0);
    }

    private static function isDefault(?string $currency): bool
    {
        return $currency === null || $currency === config('billing.currency');
    }

    public function inUse(): bool
    {
        return ServiceOption::where('product_option_choice_id', $this->id)->exists();
    }
}
