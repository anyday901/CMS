<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A heading in the public catalogue, such as "Web hosting" or "VPS". */
class ProductGroup extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'visible', 'sort_order'];

    protected $attributes = ['visible' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['visible' => 'boolean', 'sort_order' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class)->orderBy('sort_order')->orderBy('name');
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
