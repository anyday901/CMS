<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/** A domain extension that's sold, such as "com", with its prices and registrar. */
class Tld extends Model
{
    protected $fillable = ['extension', 'registrar', 'active', 'epp_required', 'id_protection', 'dns_management', 'whois_server', 'whois_available', 'sort_order'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'epp_required' => 'boolean',
            'id_protection' => 'boolean',
            'dns_management' => 'boolean',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(TldPrice::class)->orderBy('years');
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /** @param  Builder<Tld>  $query */
    public function scopeSold(Builder $query): void
    {
        $query->where('active', true)->orderBy('sort_order')->orderBy('extension');
    }

    /** ".com" */
    public function label(): string
    {
        return '.'.$this->extension;
    }

    public function priceFor(string $currency, int $years): ?TldPrice
    {
        return $this->prices->first(fn (TldPrice $price) => $price->currency === $currency && $price->years === $years);
    }

    /**
     * Prices in a currency that sell $type ("register", "transfer" or "renew"), shortest term first.
     *
     * @return Collection<int, TldPrice>
     */
    public function termsFor(string $currency, string $type): Collection
    {
        return $this->prices
            ->filter(fn (TldPrice $price) => $price->currency === $currency && $price->{$type} !== null)
            ->sortBy('years')
            ->values();
    }

    /** The cheapest one year price of $type in a currency, for the search results. */
    public function fromPrice(string $currency, string $type): ?TldPrice
    {
        return $this->termsFor($currency, $type)->first();
    }
}
