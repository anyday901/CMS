<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A support request from a client, with its conversation in messages. */
class Ticket extends Model
{
    protected $fillable = ['client_id', 'department_id', 'assigned_to', 'subject', 'status', 'priority', 'last_reply_at'];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'last_reply_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->oldest('id');
    }

    /** @param  Builder<self>  $query */
    public function scopeAwaitingStaff(Builder $query): void
    {
        $query->whereIn('status', TicketStatus::awaitingStaff());
    }
}
