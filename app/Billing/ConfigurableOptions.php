<?php

namespace App\Billing;

use App\Enums\BillingCycle;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\Service;
use App\Models\ServiceOption;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Turns submitted configurable option values into the choices a service
 * gets, and prices them in the client's currency.
 */
class ConfigurableOptions
{
    /**
     * Each choice is priced at today's price for the cycle, except a choice the
     * service already has, which keeps the price it was billed at.
     *
     * @param  array<int|string, mixed>  $input  Option id => choice id (dropdown), "1" (checkbox) or a number (quantity).
     * @param  Collection<int, ServiceOption>|null  $current  The service's options, when changing them.
     * @param  string|null  $currency  The client's currency; null for the default currency.
     * @return Collection<int, ServiceOption> Unsaved, with option and choice loaded.
     */
    public function select(Product $product, array $input, BillingCycle $cycle, ?Collection $current = null, ?string $currency = null): Collection
    {
        return $product->options()->with('choices')->get()
            ->map(fn (ProductOption $option) => $this->selectOne($option, $input[$option->id] ?? null))
            ->filter()
            ->each(function (ServiceOption $chosen) use ($cycle, $current, $currency) {
                $kept = $current?->first(fn (ServiceOption $old) => $old->product_option_choice_id === $chosen->product_option_choice_id);
                $chosen->unit_price = $kept?->unit_price ?? $chosen->choice->priceFor($cycle, $currency);
            })
            ->values();
    }

    private function selectOne(ProductOption $option, mixed $value): ?ServiceOption
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        [$choice, $quantity] = match ($option->type) {
            ProductOption::DROPDOWN => [$value === '' ? $option->choices->first() : $option->choices->firstWhere('id', (int) $value), 1],
            ProductOption::CHECKBOX => [in_array($value, ['1', 'on', 'yes'], true) ? $option->choices->first() : null, 1],
            default => [$option->choices->first(), $this->quantity($option, $value)],
        };

        if ($option->type === ProductOption::DROPDOWN && $value !== '' && $choice === null) {
            throw new InvalidArgumentException("Choose one of the listed values for {$option->name}.");
        }

        if ($choice === null || $quantity === 0) {
            return null;
        }

        return (new ServiceOption(['product_option_id' => $option->id, 'product_option_choice_id' => $choice->id, 'quantity' => $quantity]))
            ->setRelation('option', $option)
            ->setRelation('choice', $choice);
    }

    private function quantity(ProductOption $option, string $value): int
    {
        if ($value !== '' && ! ctype_digit($value)) {
            throw new InvalidArgumentException("{$option->name} must be a whole number.");
        }

        $quantity = (int) $value;

        if ($quantity < $option->min_quantity || ($option->max_quantity !== null && $quantity > $option->max_quantity)) {
            throw new InvalidArgumentException($option->max_quantity !== null
                ? "{$option->name} must be between {$option->min_quantity} and {$option->max_quantity}."
                : "{$option->name} must be at least {$option->min_quantity}.");
        }

        return $quantity;
    }

    /** @param  Collection<int, ServiceOption>  $selected */
    public function recurring(Collection $selected): int
    {
        return (int) $selected->sum(fn (ServiceOption $chosen) => $chosen->unit_price * $chosen->quantity);
    }

    /** @param  Collection<int, ServiceOption>  $selected */
    public function setupFee(Collection $selected, ?string $currency = null): int
    {
        return (int) $selected->sum(fn (ServiceOption $chosen) => $chosen->choice->setupFeeFor($currency) * $chosen->quantity);
    }

    /**
     * A choice with a price in the default currency needs its prices set in
     * the client's currency too (under the option, or with Update product
     * prices on the Currencies page) before it can be sold in it.
     *
     * @param  Collection<int, ServiceOption>  $selected
     */
    public function assertCurrency(Collection $selected, string $currency): void
    {
        $unpriced = $selected->first(fn (ServiceOption $chosen) => ! $chosen->choice->pricedIn($currency));

        if ($unpriced !== null) {
            throw new InvalidArgumentException("{$unpriced->option->name} has no prices in {$currency} yet.");
        }
    }

    /**
     * Replaces the service's options.
     *
     * @param  Collection<int, ServiceOption>  $selected
     */
    public function save(Service $service, Collection $selected): void
    {
        $service->options()->delete();
        $service->options()->saveMany($selected);
        $service->unsetRelation('options');
    }
}
