<?php

namespace App\Models\Concerns;

use App\Models\Client;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Who opened a ticket: a client, or a guest without an account who reaches
 * the ticket through a private link.
 *
 * @mixin Ticket
 */
trait HasRequester
{
    /** From someone without a client account. */
    public function isGuest(): bool
    {
        return $this->client_id === null;
    }

    /** The name of whoever opened the ticket: the client or the guest. */
    public function requesterName(): string
    {
        if (! $this->isGuest()) {
            return $this->client?->fullName() ?? 'Client';
        }

        return $this->guest_name ?: ($this->guest_email ?? 'Guest');
    }

    public function requesterFirstName(): string
    {
        $name = $this->isGuest() ? Str::before(trim((string) $this->guest_name), ' ') : $this->client?->first_name;

        return $name ?: 'there';
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
    public function sameRequester(Ticket $other): bool
    {
        return $this->isGuest()
            ? $other->isGuest() && strcasecmp((string) $this->guest_email, (string) $other->guest_email) === 0
            : $this->client_id === $other->client_id;
    }

    /**
     * @param  Builder<Ticket>  $query  Other tickets of the same client, or of guests with the same email address.
     */
    public function scopeFromRequesterOf(Builder $query, Ticket $ticket): void
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
            Ticket::where('merged_into_id', $this->id)->update(['client_id' => $client->id, 'guest_name' => null, 'guest_email' => null, 'access_key' => null]);
        });
    }
}
