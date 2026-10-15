<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment or refund on an invoice. Refunds have a negative amount and
 * point at the payment they refund.
 *
 * @mixin Transaction
 */
class TransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'client_id' => $this->client_id,
            'gateway' => $this->gateway,
            'method' => $this->methodLabel(),
            'reference' => $this->gateway_reference,
            'currency' => $this->currency,
            'amount' => (int) $this->amount,
            'fee' => (int) $this->fee,
            'pending' => (bool) $this->pending,
            'refund_of_id' => $this->refund_of_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
