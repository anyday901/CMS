<?php

namespace App\Models\Concerns;

use App\Models\Client;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A client's saved cards and their customer ids at payment gateways.
 *
 * @mixin Client
 */
trait HasSavedPaymentMethods
{
    /** Saved cards and other payment methods, the default first. */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class)->orderByDesc('is_default')->latest('id');
    }

    /** The saved method charged automatically, if any. */
    public function defaultPaymentMethod(): ?PaymentMethod
    {
        return $this->paymentMethods()->where('is_default', true)->first();
    }

    /** The client's customer id at a gateway, such as their Stripe customer. */
    public function gatewayCustomer(string $gateway): ?string
    {
        return $this->gateway_customers[$gateway] ?? null;
    }

    public function setGatewayCustomer(string $gateway, string $customerId): void
    {
        $this->forceFill(['gateway_customers' => [$gateway => $customerId] + ($this->gateway_customers ?? [])])->save();
    }
}
