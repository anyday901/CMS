<?php

namespace App\Http\Resources;

use App\Hooks\Payload;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A service in the REST API: the fields webhooks send, plus its dates.
 * Logins and passwords are left out.
 *
 * @mixin Service
 */
class ServiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return Payload::service($this->resource) + [
            'order_id' => $this->order_id,
            'parent_service_id' => $this->parent_service_id,
            'server_id' => $this->server_id,
            'suspension_reason' => $this->suspension_reason,
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'terminated_at' => $this->terminated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
