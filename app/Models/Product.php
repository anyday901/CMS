<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_group_id', 'name', 'description', 'welcome_email', 'active', 'taxable', 'is_addon', 'sort_order',
        'module', 'module_config', 'server_group_id', 'prorata_day', 'prorata_extend_day', 'trial_days', 'stock_control', 'stock_quantity',
        'overage_billing', 'overage_disk_mb', 'overage_bandwidth_mb', 'overage_disk_price', 'overage_bandwidth_price',
    ];

    protected $attributes = ['active' => true, 'taxable' => true, 'is_addon' => false, 'sort_order' => 0, 'stock_control' => false, 'stock_quantity' => 0,
        'overage_billing' => false, 'overage_disk_price' => 0, 'overage_bandwidth_price' => 0];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'taxable' => 'boolean',
            'is_addon' => 'boolean',
            'sort_order' => 'integer',
            'module_config' => 'array',
            'prorata_day' => 'integer',
            'prorata_extend_day' => 'integer',
            'trial_days' => 'integer',
            'stock_control' => 'boolean',
            'stock_quantity' => 'integer',
            'overage_billing' => 'boolean',
            'overage_disk_mb' => 'integer',
            'overage_bandwidth_mb' => 'integer',
            // Cents per GB over what is included.
            'overage_disk_price' => 'integer',
            'overage_bandwidth_price' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }

    /** The servers new services of this product go on, for modules that use servers. */
    public function serverGroup(): BelongsTo
    {
        return $this->belongsTo(ServerGroup::class);
    }

    /** Add-on products that can be ordered with this one. */
    public function addons(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'product_addons', 'product_id', 'addon_id')->orderBy('sort_order')->orderBy('name');
    }

    /** Products a service of this product can be moved to. */
    public function upgrades(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'product_upgrades', 'product_id', 'upgrade_id')->orderBy('sort_order')->orderBy('name');
    }

    /** Active products clients can order on their own (not add-ons). */
    public function scopeOrderable(Builder $query): void
    {
        $query->where('active', true)->where('is_addon', false);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('sort_order')->orderBy('id');
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function priceFor(BillingCycle $cycle, string $currency): ?ProductPrice
    {
        return $this->prices()
            ->where('billing_cycle', $cycle)
            ->where('currency', $currency)
            ->first();
    }

    /**
     * Prices in a currency, shortest cycle first.
     *
     * @return Collection<int, ProductPrice>
     */
    public function pricesIn(string $currency): Collection
    {
        return $this->prices
            ->where('currency', $currency)
            ->sortBy(fn (ProductPrice $price) => $price->billing_cycle->months() ?? 0)
            ->values();
    }

    /**
     * The cycle an add-on is billed on when ordered with a service on $cycle:
     * the same cycle, or one time when that's the only way it's sold.
     */
    public function addonCycleFor(BillingCycle $cycle, string $currency): ?BillingCycle
    {
        $cycles = $this->pricesIn($currency)->map(fn (ProductPrice $price) => $price->billing_cycle);

        return match (true) {
            $cycles->contains($cycle) => $cycle,
            $cycles->contains(BillingCycle::OneTime) => BillingCycle::OneTime,
            default => null,
        };
    }

    /** Whether $quantity more can be ordered: always, unless stock is counted. */
    public function hasStock(int $quantity = 1): bool
    {
        return ! $this->stock_control || $this->stock_quantity >= $quantity;
    }

    public function outOfStock(): bool
    {
        return ! $this->hasStock();
    }
}
