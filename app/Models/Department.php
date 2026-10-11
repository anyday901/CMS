<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A support queue such as Sales or Technical. Its email address takes piped mail and is the reply-to on ticket emails. */
class Department extends Model
{
    protected $fillable = ['name', 'email', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
}
