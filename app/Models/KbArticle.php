<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A knowledge base article, written in Markdown. */
class KbArticle extends Model
{
    protected $fillable = ['kb_category_id', 'title', 'body', 'tags', 'published', 'sort_order'];

    protected function casts(): array
    {
        return ['published' => 'boolean', 'sort_order' => 'integer', 'views' => 'integer', 'helpful' => 'integer', 'not_helpful' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(KbCategory::class, 'kb_category_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('title');
    }

    /** @param  Builder<self>  $query Published, in a category clients can see. */
    public function scopeVisible(Builder $query): void
    {
        $query->where('published', true)->whereHas('category', fn ($category) => $category->visible());
    }

    /** @param  Builder<self>  $query Every word must be in the title, text or tags. */
    public function scopeSearch(Builder $query, string $terms): void
    {
        foreach (array_slice(preg_split('/\s+/', trim($terms), -1, PREG_SPLIT_NO_EMPTY), 0, 8) as $word) {
            $like = '%'.addcslashes($word, '\\%_').'%';
            $query->where(fn ($match) => $match->where('title', 'like', $like)->orWhere('body', 'like', $like)->orWhere('tags', 'like', $like));
        }
    }

    public function isVisible(): bool
    {
        return $this->published && $this->category->isVisible();
    }

    /** @return list<string> */
    public function tagList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->tags))));
    }
}
