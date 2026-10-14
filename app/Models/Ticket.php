<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/** A support request from a client, with its conversation in messages. */
class Ticket extends Model
{
    protected $fillable = [
        'client_id', 'department_id', 'assigned_to', 'subject', 'status', 'priority', 'custom_fields',
        'last_reply_at', 'first_response_at', 'escalations',
    ];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'custom_fields' => 'array',
            'last_reply_at' => 'datetime',
            'response_due_at' => 'datetime',
            'first_response_at' => 'datetime',
            'escalations' => 'array',
        ];
    }

    /** The response due time follows the status, priority, department and last reply. */
    protected static function booted(): void
    {
        static::saving(function (self $ticket) {
            if ($ticket->isDirty(['status', 'priority', 'department_id', 'last_reply_at'])) {
                $ticket->response_due_at = $ticket->responseDueAt();
            }
        });
    }

    /** When staff should answer by under the department's SLA, or null when the ticket isn't waiting on staff or has no target. */
    public function responseDueAt(): ?CarbonInterface
    {
        if (! in_array($this->status, TicketStatus::awaitingStaff(), true)) {
            return null;
        }

        $hours = Department::find($this->department_id)?->slaHours($this->priority);

        return $hours ? ($this->last_reply_at ?? now())->copy()->addMinutes((int) round($hours * 60)) : null;
    }

    public function responseOverdue(): bool
    {
        return $this->response_due_at !== null && $this->response_due_at->isPast();
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

    /** @param  Builder<self>  $query Waiting on staff past the SLA's response time. */
    public function scopeOverdue(Builder $query): void
    {
        $query->awaitingStaff()->whereNotNull('response_due_at')->where('response_due_at', '<', now());
    }

    /** @return Collection<int, CustomField> The custom fields of the ticket's department. */
    public function customFieldDefinitions(bool $clientView = false): Collection
    {
        return CustomField::forTicket($this->department_id)->when($clientView, fn ($query) => $query->where('admin_only', false))->get();
    }

    /** @param  Builder<self>  $query */
    public function scopeAwaitingStaff(Builder $query): void
    {
        $query->whereIn('status', TicketStatus::awaitingStaff());
    }
}
