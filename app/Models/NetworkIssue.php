<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Planned maintenance or a current problem, shown on the network status page. */
class NetworkIssue extends Model
{
    public const RESOLVED = 'resolved';

    public const SCHEDULED = 'scheduled';

    public const STATUSES = [
        'reported' => 'Reported',
        'investigating' => 'Investigating',
        'in_progress' => 'In progress',
        'outage' => 'Outage',
        self::SCHEDULED => 'Scheduled',
        self::RESOLVED => 'Resolved',
    ];

    public const PRIORITIES = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];

    protected $fillable = ['title', 'description', 'server_id', 'affecting', 'priority', 'status', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @param  Builder<self>  $query Not resolved and already started. */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('status', '!=', self::RESOLVED)->where('starts_at', '<=', now())
            ->orderByRaw("case priority when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->latest('starts_at');
    }

    /** @param  Builder<self>  $query Planned work that hasn't started yet, soonest first. */
    public function scopeUpcoming(Builder $query): void
    {
        $query->where('status', self::SCHEDULED)->where('starts_at', '>', now())->orderBy('starts_at');
    }

    /** @param  Builder<self>  $query */
    public function scopeResolved(Builder $query): void
    {
        $query->where('status', self::RESOLVED)->latest('ends_at')->latest('starts_at');
    }

    /** "web1" or what staff typed, such as "Email". */
    public function affects(): string
    {
        return $this->server->name ?? ($this->affecting ?: 'Everything');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function priorityLabel(): string
    {
        return self::PRIORITIES[$this->priority] ?? ucfirst((string) $this->priority);
    }
}
