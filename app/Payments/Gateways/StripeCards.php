<?php

namespace App\Payments\Gateways;

use App\Models\Client;
use App\Models\PaymentMethod;
use App\Payments\PaymentFailed;
use Illuminate\Support\Facades\DB;

/** Clients' Stripe customers and the cards saved on them. */
class StripeCards
{
    public function __construct(private StripeApi $api) {}

    /** Creates a SetupIntent for adding a card in the portal and returns its client secret. */
    public function createSetupIntent(Client $client): string
    {
        $response = $this->api->request()->post('/v1/setup_intents', [
            'customer' => $this->customerFor($client),
            'usage' => 'off_session',
            'payment_method_types' => ['card'],
            'metadata' => ['client_id' => (string) $client->id],
        ]);

        return $this->api->ok($response, 'start adding a card')->json('client_secret');
    }

    /** Saves the card of a SetupIntent the browser confirmed. */
    public function confirmSetup(Client $client, string $setupIntentId): PaymentMethod
    {
        $intent = $this->api->retrieve('setup_intents', 'seti', $setupIntentId, ['payment_method']);

        if (($intent['metadata']['client_id'] ?? null) !== (string) $client->id || ! is_array($intent['payment_method'] ?? null)) {
            throw new PaymentFailed('This card could not be found. Please try again.');
        }

        if (($intent['status'] ?? null) !== 'succeeded') {
            throw new PaymentFailed($this->api->declineMessage($intent['last_setup_error'] ?? null, 'Your card could not be saved. Please try another card.'));
        }

        return $this->saveMethod($client, $intent['payment_method'], (string) $intent['customer']);
    }

    /** The client's Stripe customer, created the first time they save a card. */
    public function customerFor(Client $client): string
    {
        if ($customer = $client->gatewayCustomer(Stripe::KEY)) {
            return $customer;
        }

        $response = $this->api->request()
            ->withHeaders(['Idempotency-Key' => "customer-client-{$client->id}"])
            ->post('/v1/customers', array_filter([
                'email' => $client->email,
                'name' => $client->fullName(),
                'metadata' => ['client_id' => (string) $client->id],
            ]));

        $customer = (string) $this->api->ok($response, 'create a customer')->json('id');
        $client->setGatewayCustomer(Stripe::KEY, $customer);

        return $customer;
    }

    /** Keeps a card Stripe has attached to the client's customer. The first one becomes the default. */
    public function saveMethod(Client $client, array $method, string $customer): PaymentMethod
    {
        return DB::transaction(function () use ($client, $method, $customer) {
            $saved = PaymentMethod::firstOrNew(['gateway' => Stripe::KEY, 'gateway_reference' => $method['id']]);

            if ($saved->exists && $saved->client_id !== $client->id) {
                throw new PaymentFailed('This card could not be saved. Please contact us.');
            }

            $card = $method['card'] ?? [];
            $saved->fill([
                'client_id' => $client->id,
                'gateway_customer_id' => $customer,
                'type' => $method['type'] ?? 'card',
                'brand' => $card['brand'] ?? null,
                'last4' => $card['last4'] ?? null,
                'exp_month' => $card['exp_month'] ?? null,
                'exp_year' => $card['exp_year'] ?? null,
            ])->save();

            if (! $client->paymentMethods()->where('is_default', true)->exists()) {
                $saved->makeDefault();
            }

            return $saved;
        });
    }
}
