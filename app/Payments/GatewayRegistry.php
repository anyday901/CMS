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

    /**
     * Enabled gateways that keep cards to charge later.
     *
     * @return Collection<int, Gateway&ChargesSavedMethods>
     */
    public function savingMethods(): Collection
    {
        return $this->enabled()->filter(fn (Gateway $gateway) => $gateway instanceof ChargesSavedMethods)->values();
    }

    /** The enabled gateway that can charge methods saved under this key, if any. */
    public function charger(string $key): ?ChargesSavedMethods
    {
        $gateway = $this->find($key);

        return $gateway instanceof ChargesSavedMethods ? $gateway : null;
    }
}
