<?php

namespace App\Billing;

use App\Enums\BillingCycle;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\Service;
use App\Models\ServiceOption;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Turns submitted configurable option values into the choices a service
 * gets, and prices them. Option prices are set in the billing currency only.
 */
class ConfigurableOptions
{
    /**
     * @param  array<int|string, mixed>  $input  Option id => choice id (dropdown), "1" (checkbox) or a number (quantity).
     * @return Collection<int, ServiceOption> Unsaved, with option and choice loaded.
     */
    public function select(Product $product, array $input): Collection
    {
        return $product->options()->with('choices')->get()
            ->map(fn (ProductOption $option) => $this->selectOne($option, $input[$option->id] ?? null))
            ->filter()
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
    public function recurring(Collection $selected, BillingCycle $cycle): int
    {
        return (int) $selected->sum(fn (ServiceOption $chosen) => $chosen->choice->priceFor($cycle) * $chosen->quantity);
    }

    /** @param  Collection<int, ServiceOption>  $selected */
    public function setupFee(Collection $selected): int
    {
        return (int) $selected->sum(fn (ServiceOption $chosen) => $chosen->choice->setup_fee * $chosen->quantity);
    }

    /**
     * Option prices exist only in the billing currency, so a client billed in
     * another currency can only have free options.
     *
     * @param  Collection<int, ServiceOption>  $selected
     */
    public function assertCurrency(Collection $selected, Client $client, BillingCycle $cycle): void
    {
        if ($client->currency !== config('billing.currency') && ($this->recurring($selected, $cycle) > 0 || $this->setupFee($selected) > 0)) {
            throw new InvalidArgumentException('Configurable option prices are only set in '.config('billing.currency').", but this client is billed in {$client->currency}.");
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
