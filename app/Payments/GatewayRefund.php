<?php

namespace App\Payments;

/** A refund the gateway accepted. Pending refunds can still complete or fail. */
final readonly class GatewayRefund
{
    public const COMPLETED = 'completed';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    public function __construct(public string $id, public string $status) {}

    public function pending(): bool
    {
        return $this->status === self::PENDING;
    }
}
