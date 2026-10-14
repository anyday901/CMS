<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** News for clients, shown in the portal and on the public announcements page. */
class Announcement extends Model
{
    protected $fillable = ['title', 'body', 'published', 'published_at'];

    protected function casts(): array
    {
        return ['published' => 'boolean', 'published_at' => 'datetime'];
    }

    /** @param  Builder<self>  $query Published and not dated in the future, newest first. */
    public function scopeVisible(Builder $query): void
    {
        $query->where('published', true)->where('published_at', '<=', now())->latest('published_at')->latest('id');
    }

    public function isVisible(): bool
    {
        return $this->published && $this->published_at->lte(now());
    }
}
