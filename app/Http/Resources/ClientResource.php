<?php

namespace App\Http\Resources;

use App\Hooks\Payload;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A client in the REST API: the fields webhooks send, plus the address.
 *
 * @mixin Client
 */
class ClientResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return Payload::client($this->resource) + [
            'address1' => $this->address1,
            'address2' => $this->address2,
            'city' => $this->city,
            'state' => $this->state,
            'postcode' => $this->postcode,
            'tax_exempt' => (bool) $this->tax_exempt,
            'custom_fields' => (object) ($this->custom_fields ?? []),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
