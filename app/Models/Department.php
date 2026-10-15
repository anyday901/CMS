<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Tickets\BusinessHours;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A support queue such as Sales or Technical. Its email address takes piped
 * mail and is the reply-to on ticket emails. Its SLA sets how many hours
 * staff have to answer, by priority, counting every hour or only business
 * hours. It can ask clients to rate the support when a ticket is closed.
 */
class Department extends Model
{
    protected $fillable = ['name', 'email', 'active', 'sla_hours', 'sla_business_hours', 'feedback_requests'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sla_hours' => 'array', 'sla_business_hours' => 'boolean', 'feedback_requests' => 'boolean'];
    }

    /** Hours staff have to answer a ticket of this priority, or null for no target. */
    public function slaHours(TicketPriority $priority): ?float
    {
        $hours = $this->sla_hours[$priority->value] ?? null;

        return is_numeric($hours) && $hours > 0 ? (float) $hours : null;
    }

    /** When a ticket waiting since $from is due an answer, or null for no target. */
    public function responseDueAt(TicketPriority $priority, CarbonInterface $from): ?CarbonInterface
    {
        $hours = $this->slaHours($priority);

        return match (true) {
            $hours === null => null,
            $this->sla_business_hours => BusinessHours::fromConfig()->addHours($from, $hours),
            default => $from->copy()->addMinutes((int) round($hours * 60)),
        };
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
