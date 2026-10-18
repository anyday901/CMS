<?php

namespace App\Billing;

use App\Models\Activity;
use App\Models\Client;
use App\Models\PaymentMethod;
use App\Payments\GatewayRegistry;
use App\Payments\PaymentFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Saved payment methods: setting the default and removing them, at the gateway too. */
class PaymentMethods
{
    public function __construct(private GatewayRegistry $gateways) {}

    public function makeDefault(PaymentMethod $method): void
    {
        $method->makeDefault();
        Activity::record("Made {$method->label()} the default payment method", $method->client);
    }

    /**
     * Removes the method at its gateway, so it can't be charged again, then
     * here. When its gateway is no longer set up it is only removed here.
     *
     * @throws PaymentFailed when the gateway refuses
     */
    public function remove(PaymentMethod $method): void
    {
        $this->gateways->charger($method->gateway)?->removeMethod($method);
        $this->forget($method);
        Activity::record("Removed the saved payment method {$method->label()}", $method->client);
    }

    /** Removes the method here only, such as after the gateway reports it removed. */
    public function forget(PaymentMethod $method): void
    {
        DB::transaction(function () use ($method) {
            $method->delete();

            // Another saved method takes over as the default.
            if ($method->is_default) {
                PaymentMethod::where('client_id', $method->client_id)->latest('id')->first()?->makeDefault();
            }
        });
    }

    /** Removes all of a client's methods, as far as their gateways allow, such as when anonymising them. */
    public function removeAll(Client $client): void
    {
        foreach ($client->paymentMethods()->get() as $method) {
            try {
                $this->gateways->charger($method->gateway)?->removeMethod($method);
            } catch (PaymentFailed $e) {
                Log::warning('Could not remove a saved payment method at its gateway', ['method' => $method->id, 'reason' => $e->getMessage()]);
            }

            $method->delete();
        }
    }
}
