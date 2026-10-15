<?php

namespace App\Http\Resources;

use App\Models\TicketMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One post on a ticket. "from" is client, guest or staff. Attachments are
 * listed by name; they can be downloaded in the admin area.
 *
 * @mixin TicketMessage
 */
class TicketMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'from' => match (true) {
                $this->guest => 'guest',
                $this->fromStaff() => 'staff',
                default => 'client',
            },
            'author' => $this->authorName(),
            'body' => $this->body,
            'internal' => (bool) $this->internal,
            'via' => $this->via,
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->pluck('name')->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
