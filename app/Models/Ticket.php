<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/** A support request from a client, with its conversation in messages. */
class Ticket extends Model
{
    protected $fillable = [
        'client_id', 'merged_into_id', 'department_id', 'assigned_to', 'subject', 'status', 'priority', 'custom_fields',
        'last_reply_at', 'first_response_at', 'closed_at', 'escalations',
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
            'closed_at' => 'datetime',
            'escalations' => 'array',
        ];
    }

    /** The response due time follows the status, priority, department and last reply; closed_at follows the status. */
    protected static function booted(): void
    {
        static::saving(function (self $ticket) {
            if ($ticket->isDirty(['status', 'priority', 'department_id', 'last_reply_at'])) {
                $ticket->response_due_at = $ticket->responseDueAt();
            }

            if ($ticket->isDirty('status')) {
                $ticket->closed_at = $ticket->status === TicketStatus::Closed ? ($ticket->closed_at ?? now()) : null;
            }
        });
    }

    /** When staff should answer by under the department's SLA, or null when the ticket isn't waiting on staff or has no target. */
    public function responseDueAt(): ?CarbonInterface
    {
        if (! in_array($this->status, TicketStatus::awaitingStaff(), true)) {
            return null;
        }

        return Department::find($this->department_id)?->responseDueAt($this->priority, $this->last_reply_at ?? now());
    }

    /**
     * Works out the response due time again for tickets waiting on staff,
     * after a department's targets or the business hours change.
     *
     * @param  Builder<self>|null  $tickets
     */
    public static function refreshResponseDue(?Builder $tickets = null): void
    {
        ($tickets ?? self::query())->awaitingStaff()->lazyById(200)
            ->each(fn (self $ticket) => $ticket->forceFill(['response_due_at' => $ticket->responseDueAt()])->saveQuietly());
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

    /** In the order they were written, which after a merge isn't always id order. */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->oldest()->oldest('id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(TicketTag::class)->orderBy('name');
    }

    public function feedback(): HasOne
    {
        return $this->hasOne(TicketFeedback::class);
    }

    /** The ticket this one was merged into. */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /** Whether the client should be asked to rate the support on this ticket. */
    public function wantsFeedback(): bool
    {
        return $this->status === TicketStatus::Closed && $this->merged_into_id === null
            && $this->department?->feedback_requests && ! $this->feedback()->exists();
    }

    /** @param  Builder<self>  $query Not merged into another ticket. */
    public function scopeListed(Builder $query): void
    {
        $query->whereNull('merged_into_id');
    }

    /**
     * Replaces the ticket's tags with these names, adding new tags and
     * removing tags no ticket uses any more.
     *
     * @param  list<string>  $names
     */
    public function syncTags(array $names): void
    {
        $before = $this->tags()->pluck('ticket_tags.id');
        $ids = collect(TicketTag::normalize($names))->map(fn (string $name) => TicketTag::firstOrCreate(['name' => $name])->id);
        $this->tags()->sync($ids);
        TicketTag::whereIn('id', $before->diff($ids))->doesntHave('tickets')->delete();
        $this->unsetRelation('tags');
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
