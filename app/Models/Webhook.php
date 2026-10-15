<?php

namespace App\Models;

use App\Hooks\Hooks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An outgoing webhook: a URL that gets a signed JSON POST for each event it subscribes to. */
class Webhook extends Model
{
    protected $fillable = ['name', 'url', 'secret', 'events', 'active'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'active' => 'boolean',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /** Active webhooks subscribed to the event (or to every event). */
    public function scopeFor(Builder $query, string $event): void
    {
        $query->where('active', true)->where(fn ($q) => $q->whereJsonContains('events', $event)->orWhereJsonContains('events', Hooks::ANY));
    }

    public function listensToEverything(): bool
    {
        return in_array(Hooks::ANY, $this->events ?? [], true);
    }
}
