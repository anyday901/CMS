<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product in the REST API, with its prices on a single product. Module
 * settings are left out, since they can hold server passwords.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_group_id' => $this->product_group_id,
            'group' => $this->group?->name,
            'name' => $this->name,
            'description' => $this->description,
            'active' => (bool) $this->active,
            'is_addon' => (bool) $this->is_addon,
            'taxable' => (bool) $this->taxable,
            'module' => $this->module,
            'trial_days' => $this->trial_days,
            'stock_control' => (bool) $this->stock_control,
            'stock_quantity' => $this->stock_control ? (int) $this->stock_quantity : null,
            'prices' => $this->whenLoaded('prices', fn () => $this->prices->map(fn (ProductPrice $price) => [
                'billing_cycle' => $price->billing_cycle?->value,
                'currency' => $price->currency,
                'price' => (int) $price->price,
                'setup_fee' => (int) $price->setup_fee,
            ])->values()),
        ];
    }
}
