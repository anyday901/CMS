<?php

namespace App\Models;

use App\Hooks\Hooks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A Slack, Discord or Microsoft Teams channel that staff notifications are posted to. */
class NotificationChannel extends Model
{
    public const TYPES = ['slack' => 'Slack', 'discord' => 'Discord', 'teams' => 'Microsoft Teams'];

    protected $fillable = ['type', 'name', 'webhook_url', 'events', 'active', 'last_sent_at', 'last_error'];

    protected $hidden = ['webhook_url'];

    protected function casts(): array
    {
        return [
            'webhook_url' => 'encrypted',
            'events' => 'array',
            'active' => 'boolean',
            'last_sent_at' => 'datetime',
        ];
    }

    /** Active channels that want the event (or every event). */
    public function scopeFor(Builder $query, string $event): void
    {
        $query->where('active', true)->where(fn ($q) => $q->whereJsonContains('events', $event)->orWhereJsonContains('events', Hooks::ANY));
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
