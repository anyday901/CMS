<?php

namespace App\Models;

use App\Enums\TicketPriority;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A support queue such as Sales or Technical. Its email address takes piped
 * mail and is the reply-to on ticket emails. Its SLA sets how many hours
 * staff have to answer, by priority.
 */
class Department extends Model
{
    protected $fillable = ['name', 'email', 'active', 'sla_hours'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sla_hours' => 'array'];
    }

    /** Hours staff have to answer a ticket of this priority, or null for no target. */
    public function slaHours(TicketPriority $priority): ?float
    {
        $hours = $this->sla_hours[$priority->value] ?? null;

        return is_numeric($hours) && $hours > 0 ? (float) $hours : null;
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
