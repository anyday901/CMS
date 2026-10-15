<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A knowledge base category. Categories go one level deep: a top-level
 * category can have subcategories. Its name and description can be
 * translated; a translation's title is the name and its body the description.
 */
class KbCategory extends Model
{
    use HasTranslations;

    protected $fillable = ['parent_id', 'name', 'description', 'visible', 'sort_order'];

    protected $attributes = ['visible' => true];

    protected function casts(): array
    {
        return ['visible' => 'boolean', 'sort_order' => 'integer'];
    }

    protected function name(): Attribute
    {
        return Attribute::get(fn (?string $value) => $this->fromTranslation('title', $value));
    }

    protected function description(): Attribute
    {
        return Attribute::get(fn (?string $value) => $this->fromTranslation('body', $value));
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->ordered();
    }

    public function articles(): HasMany
    {
        return $this->hasMany(KbArticle::class)->ordered();
    }

    /** @param  Builder<self>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    /** @param  Builder<self>  $query Not hidden, and not under a hidden category. */
    public function scopeVisible(Builder $query): void
    {
        $query->where('visible', true)
            ->where(fn ($query) => $query->whereNull('parent_id')->orWhereHas('parent', fn ($parent) => $parent->where('visible', true)));
    }

    public function isVisible(): bool
    {
        // getAttribute(), because $this->visible is Eloquent's list of serialized attributes.
        return $this->getAttribute('visible') && ($this->parent === null || $this->parent->getAttribute('visible'));
    }
}
