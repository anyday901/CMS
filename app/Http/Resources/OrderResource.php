<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order in the REST API: the fields webhooks send, with its services,
 * and its invoice on a single order. Load services.product first.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'invoice_id' => $this->invoice_id,
            'status' => $this->status?->value,
            'promotion_code' => $this->promotion_code,
            'service_ids' => $this->services->pluck('id')->all(),
            'created_at' => $this->created_at?->toIso8601String(),
            'whmcs_number' => $this->whmcs_number,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'services' => ServiceResource::collection($this->services),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? InvoiceResource::make($this->invoice) : null),
        ];
    }
}
