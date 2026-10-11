<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One choice of a configurable option, with a price per billing cycle in cents. */
class ProductOptionChoice extends Model
{
    protected $fillable = ['name', 'prices', 'setup_fee', 'sort_order'];

    protected function casts(): array
    {
        return ['prices' => 'array', 'setup_fee' => 'integer', 'sort_order' => 'integer'];
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }

    /** The price for one unit on this cycle; a cycle without a price is free. */
    public function priceFor(BillingCycle $cycle): int
    {
        return (int) ($this->prices[$cycle->value] ?? 0);
    }

    public function inUse(): bool
    {
        return ServiceOption::where('product_option_choice_id', $this->id)->exists();
    }
}
