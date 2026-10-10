<?php

namespace App\Payments;

use Illuminate\Support\Collection;

class GatewayRegistry
{
    /** @return Collection<int, Gateway> */
    public function enabled(): Collection
    {
        return collect(config('payments.gateways'))
            ->map(fn (string $class) => app($class))
            ->filter(fn (Gateway $gateway) => $gateway->isEnabled())
            ->values();
    }

    /** The enabled gateway that recorded payments under this key, if any. */
    public function find(string $key): ?Gateway
    {
        return $this->enabled()->first(fn (Gateway $gateway) => $gateway->key() === $key);
    }
}
