<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One post on a ticket: from the client, a staff reply, or an internal staff note. */
class TicketMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['ticket_id', 'user_id', 'client_id', 'body', 'internal', 'via', 'email_message_id'];

    protected function casts(): array
    {
        return ['internal' => 'boolean'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    public function fromStaff(): bool
    {
        return $this->client_id === null;
    }

    public function authorName(): string
    {
        return $this->fromStaff()
            ? ($this->user->name ?? 'Staff')
            : ($this->client?->fullName() ?? 'Client');
    }
}
