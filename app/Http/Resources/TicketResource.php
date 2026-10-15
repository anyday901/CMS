<?php

namespace App\Http\Resources;

use App\Hooks\Payload;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ticket in the REST API: the fields webhooks send, plus its messages
 * when they were loaded (on a single ticket). Internal staff notes are
 * never loaded for the API.
 *
 * @mixin Ticket
 */
class TicketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return Payload::ticket($this->resource) + [
            'guest_name' => $this->guest_name,
            'merged_into_id' => $this->merged_into_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'messages' => TicketMessageResource::collection($this->whenLoaded('messages')),
        ];
    }
}
