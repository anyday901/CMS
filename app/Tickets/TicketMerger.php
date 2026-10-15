<?php

namespace App\Tickets;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Activity;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Merges tickets and splits messages off into a new ticket.
 *
 * Merging moves every message of the other tickets onto one ticket and
 * closes the others, which then point at it: links, portal pages and email
 * replies to their numbers land on the merged ticket. Only tickets of the
 * same client, or the same guest email address, can be merged, so nobody
 * sees someone else's messages.
 */
class TicketMerger
{
    public function __construct(private TicketDesk $desk) {}

    /**
     * @param  Collection<int, Ticket>  $others
     *
     * @throws InvalidArgumentException when a ticket can't be merged into $into
     */
    public function merge(Ticket $into, Collection $others, User $by): void
    {
        $others = $others->reject(fn (Ticket $ticket) => $ticket->is($into))->values();

        foreach ($others as $ticket) {
            $problem = match (true) {
                ! $ticket->sameRequester($into) => "#{$ticket->id} belongs to another ".($into->isGuest() ? 'person.' : 'client.'),
                $ticket->merged_into_id !== null => "#{$ticket->id} was already merged into #{$ticket->merged_into_id}.",
                default => null,
            };

            if ($problem !== null) {
                throw new InvalidArgumentException($problem);
            }
        }

        if ($into->merged_into_id !== null || $others->isEmpty()) {
            throw new InvalidArgumentException('Pick at least one other ticket of this client to merge into this one.');
        }

        DB::transaction(function () use ($into, $others, $by) {
            $ids = $others->modelKeys();
            TicketMessage::whereIn('ticket_id', $ids)->update(['ticket_id' => $into->id]);
            // Tickets merged into these earlier now point straight at the merged ticket.
            Ticket::whereIn('merged_into_id', $ids)->update(['merged_into_id' => $into->id]);

            $into->fill([
                'last_reply_at' => $others->pluck('last_reply_at')->push($into->last_reply_at)->filter()->max(),
                'first_response_at' => $others->pluck('first_response_at')->push($into->first_response_at)->filter()->min(),
                'custom_fields' => array_replace(...$others->map(fn (Ticket $ticket) => $ticket->custom_fields ?? [])->push($into->custom_fields ?? [])->all()) ?: null,
                'status' => $this->statusFromMessages($into, $into->status === TicketStatus::Closed && $others->every(fn (Ticket $ticket) => $ticket->status === TicketStatus::Closed)),
                'escalations' => null,
            ]);

            $into->save();
            $into->syncTags($others->flatMap(fn (Ticket $ticket) => $ticket->tags->pluck('name'))->merge($into->tags->pluck('name'))->all());

            foreach ($others as $ticket) {
                $ticket->update(['merged_into_id' => $into->id, 'status' => TicketStatus::Closed, 'escalations' => null]);
            }

            $list = $others->map(fn (Ticket $ticket) => "#{$ticket->id} ({$ticket->subject})")->join(', ', ' and ');
            $into->messages()->create(['user_id' => $by->id, 'internal' => true, 'body' => "Merged {$list} into this ticket."]);
            Activity::record("Merged {$list} into ticket #{$into->id}", $into, ['merged' => $ids]);
        });
    }

    /**
     * Who a ticket waits on after messages moved in or out, from who wrote
     * last: staff when the client did (unless staff put it on hold), the
     * client when staff did. A closed ticket stays closed when $staysClosed.
     */
    private function statusFromMessages(Ticket $ticket, bool $staysClosed): TicketStatus
    {
        $public = $ticket->messages()->where('internal', false);
        $latest = (clone $public)->get()->last();

        return match (true) {
            $latest === null || $staysClosed || $ticket->status === TicketStatus::OnHold => $ticket->status,
            ! $latest->fromStaff() => (clone $public)->whereNull('client_id')->where('guest', false)->exists() ? TicketStatus::CustomerReply : TicketStatus::Open,
            default => TicketStatus::Answered,
        };
    }

    /**
     * Moves the chosen messages to a new ticket for the same client.
     *
     * @param  list<int>  $messageIds
     * @param  array{subject: string, department_id: int, priority: TicketPriority}  $attributes
     *
     * @throws InvalidArgumentException when the messages can't be split off
     */
    public function split(Ticket $from, array $messageIds, array $attributes, User $by, bool $notify = false): Ticket
    {
        $moving = $from->messages()->whereKey($messageIds)->get();

        if ($moving->isEmpty() || $moving->count() !== count(array_unique($messageIds))) {
            throw new InvalidArgumentException('Pick the messages to move from this ticket.');
        }

        if ($moving->every(fn (TicketMessage $message) => $message->internal)) {
            throw new InvalidArgumentException('Pick at least one message the client can see; internal notes alone would make an empty ticket.');
        }

        if ($from->messages()->whereKeyNot($moving->modelKeys())->where('internal', false)->doesntExist()) {
            throw new InvalidArgumentException('Leave at least one message, other than internal notes, on this ticket.');
        }

        $ticket = DB::transaction(function () use ($from, $moving, $attributes, $by) {
            $public = $moving->where('internal', false);
            $latest = $public->sortBy([['created_at', 'asc'], ['id', 'asc']])->last();

            $ticket = Ticket::create([
                'client_id' => $from->client_id,
                'guest_name' => $from->guest_name,
                'guest_email' => $from->guest_email,
                'department_id' => $attributes['department_id'],
                'subject' => Str::limit(trim($attributes['subject']), 250, ''),
                'priority' => $attributes['priority'],
                'status' => $latest->fromStaff() ? TicketStatus::Answered : TicketStatus::Open,
                'assigned_to' => $from->assigned_to,
                'custom_fields' => $from->custom_fields,
                'last_reply_at' => $latest->created_at,
            ]);
            $ticket->syncTags($from->tags->pluck('name')->all());
            TicketMessage::whereKey($moving->modelKeys())->update(['ticket_id' => $ticket->id]);

            $remaining = $from->messages()->where('internal', false)->get()->last();
            $from->update([
                'last_reply_at' => $remaining?->created_at ?? $from->last_reply_at,
                'status' => $this->statusFromMessages($from, $from->status === TicketStatus::Closed),
            ]);

            $ticket->messages()->create(['user_id' => $by->id, 'internal' => true, 'body' => "Split from ticket #{$from->id} ({$from->subject})."]);
            $from->messages()->create(['user_id' => $by->id, 'internal' => true, 'body' => "Moved {$moving->count()} message(s) to ticket #{$ticket->id} ({$ticket->subject})."]);
            Activity::record("Split {$moving->count()} message(s) from ticket #{$from->id} into ticket #{$ticket->id}", $ticket, ['from' => $from->id]);

            return $ticket;
        });

        if ($notify) {
            $this->desk->sendOpened($ticket);
        }

        return $ticket;
    }
}
