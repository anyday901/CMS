<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A configurable option on a product: a dropdown of choices (such as disk
 * size), a checkbox (such as backups) or a quantity (such as extra IPs). Each
 * choice adds its own price for the service's billing cycle.
 */
class ProductOption extends Model
{
    public const DROPDOWN = 'dropdown';

    public const CHECKBOX = 'checkbox';

    public const QUANTITY = 'quantity';

    public const TYPES = [self::DROPDOWN => 'Dropdown', self::CHECKBOX => 'Checkbox', self::QUANTITY => 'Quantity'];

    protected $fillable = ['name', 'type', 'min_quantity', 'max_quantity', 'sort_order'];

    protected function casts(): array
    {
        return ['min_quantity' => 'integer', 'max_quantity' => 'integer', 'sort_order' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function choices(): HasMany
    {
        return $this->hasMany(ProductOptionChoice::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Checkboxes and quantities have a single price, kept as their only choice. */
    public function hasOneChoice(): bool
    {
        return $this->type !== self::DROPDOWN;
    }

    public function inUse(): bool
    {
        return ServiceOption::where('product_option_id', $this->id)->exists();
    }
}
