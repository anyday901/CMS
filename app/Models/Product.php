<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'active', 'module', 'module_config'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'module_config' => 'array',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
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
}
