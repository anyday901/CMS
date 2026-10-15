<?php

namespace App\Http\Resources;

use App\Hooks\Payload;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An invoice in the REST API: the fields webhooks send, plus its lines and
 * payments when they were loaded (on a single invoice).
 *
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return Payload::invoice($this->resource) + [
            'tax_name' => $this->tax_name,
            'tax_rate' => (int) $this->tax_rate,
            'tax_inclusive' => (bool) $this->tax_inclusive,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (InvoiceItem $item) => [
                'id' => $item->id,
                'service_id' => $item->service_id,
                'type' => $item->type,
                'description' => $item->description,
                'amount' => (int) $item->amount,
                'taxable' => (bool) $item->taxable,
                'period_start' => $item->period_start?->toDateString(),
                'period_end' => $item->period_end?->toDateString(),
            ])->values()),
            'payments' => $this->whenLoaded('transactions', fn () => $this->transactions->map(fn (Transaction $payment) => TransactionResource::make($payment)->toArray($request))->values()),
        ];
    }
}
