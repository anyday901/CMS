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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A support request, with its conversation in messages. It belongs to a
 * client, or to a guest (someone without an account) known by name and email
 * address, who reaches it through a link with the ticket's access key.
 */
class Ticket extends Model
{
    protected $fillable = [
        'client_id', 'guest_name', 'guest_email', 'access_key', 'merged_into_id', 'department_id', 'assigned_to', 'subject', 'status', 'priority', 'custom_fields',
        'last_reply_at', 'first_response_at', 'closed_at', 'escalations',
    ];

    protected $hidden = ['access_key'];

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

    /** From someone without a client account. */
    public function isGuest(): bool
    {
        return $this->client_id === null;
    }

    /** The name of whoever opened the ticket: the client or the guest. */
    public function requesterName(): string
    {
        return $this->isGuest() ? ($this->guest_name ?: $this->guest_email ?? 'Guest') : ($this->client?->fullName() ?? 'Client');
    }

    public function requesterFirstName(): string
    {
        return $this->isGuest() ? (Str::before(trim((string) $this->guest_name), ' ') ?: 'there') : ($this->client?->first_name ?? 'there');
    }

    public function requesterEmail(): ?string
    {
        return $this->isGuest() ? $this->guest_email : $this->client?->email;
    }

    /** Where whoever opened the ticket reads it: the portal for a client, the guest link for a guest. */
    public function viewUrl(): string
    {
        return $this->isGuest() ? $this->guestUrl() : route('portal.tickets.show', $this);
    }

    /** The private link to a guest ticket. It needs no login, so it is only ever emailed to the guest. */
    public function guestUrl(): string
    {
        if ($this->access_key === null) {
            $this->forceFill(['access_key' => Str::random(40)])->save();
        }

        return route('tickets.guest.show', [$this, $this->access_key]);
    }

    /** Whether two tickets were opened by the same client, or by guests with the same email address. */
    public function sameRequester(self $other): bool
    {
        return $this->isGuest()
            ? $other->isGuest() && strcasecmp((string) $this->guest_email, (string) $other->guest_email) === 0
            : $this->client_id === $other->client_id;
    }

    /**
     * @param  Builder<self>  $query  Other tickets of the same client, or of guests with the same email address.
     */
    public function scopeFromRequesterOf(Builder $query, self $ticket): void
    {
        $ticket->isGuest()
            ? $query->whereNull('client_id')->whereRaw('lower(guest_email) = ?', [strtolower((string) $ticket->guest_email)])
            : $query->where('client_id', $ticket->client_id);
    }

    public function hasAccessKey(string $key): bool
    {
        return $this->access_key !== null && hash_equals($this->access_key, $key);
    }

    /**
     * Moves a guest ticket to a client account: the guest's messages become
     * the client's, and the link the guest had stops working.
     */
    public function linkToClient(Client $client): void
    {
        DB::transaction(function () use ($client) {
            $this->messages()->where('guest', true)->update(['client_id' => $client->id, 'guest' => false]);
            $this->feedback()->update(['client_id' => $client->id]);
            $this->update(['client_id' => $client->id, 'guest_name' => null, 'guest_email' => null, 'access_key' => null]);
            self::where('merged_into_id', $this->id)->update(['client_id' => $client->id, 'guest_name' => null, 'guest_email' => null, 'access_key' => null]);
        });
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
