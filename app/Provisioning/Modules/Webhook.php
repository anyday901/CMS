<?php

namespace App\Provisioning\Modules;

use App\Models\Service;
use App\Provisioning\ProvisioningModule;
use App\Provisioning\ProvisioningResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Sends each action to a URL of your choice as a JSON POST, so services can
 * be set up by any system that accepts a webhook.
 *
 * With a secret set, the body is signed: X-Webhook-Signature is
 * "sha256=" followed by the hex HMAC-SHA256 of the raw body. X-Webhook-Delivery
 * is the same for retries of one action, so the receiver can skip repeats.
 * Any 2xx response counts as done. A JSON reply may include "message" (shown
 * to staff) and "data" (an object saved on the service and sent back on later
 * actions, e.g. a remote account id).
 */
class Webhook implements ProvisioningModule
{
    public function key(): string
    {
        return 'webhook';
    }

    public function label(): string
    {
        return 'Webhook';
    }

    public function configFields(): array
    {
        return [
            'url' => ['label' => 'URL', 'type' => 'url', 'required' => true, 'help' => 'Receives a JSON POST for create, suspend, unsuspend and terminate.'],
            'secret' => ['label' => 'Signing secret', 'help' => 'Optional. Signs each request so the receiver can check it came from here.'],
        ];
    }

    public function create(Service $service): ProvisioningResult
    {
        return $this->send($service, 'create');
    }

    public function suspend(Service $service): ProvisioningResult
    {
        return $this->send($service, 'suspend');
    }

    public function unsuspend(Service $service): ProvisioningResult
    {
        return $this->send($service, 'unsuspend');
    }

    public function terminate(Service $service): ProvisioningResult
    {
        return $this->send($service, 'terminate');
    }

    private function send(Service $service, string $action): ProvisioningResult
    {
        $config = $service->product->module_config ?? [];
        $url = (string) ($config['url'] ?? '');

        if (! Str::startsWith($url, ['https://', 'http://'])) {
            return ProvisioningResult::failed('The product has no webhook URL. Set one on the product page.');
        }

        // Actions run one at a time in order, so the next number to finish
        // identifies this action, and stays the same when staff run it again
        // after a failure.
        $delivery = "service-{$service->id}-action-".($service->provisioning_finished + 1);
        $body = json_encode($this->payload($service, $action, $delivery), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $headers = ['X-Webhook-Delivery' => $delivery, 'User-Agent' => config('app.name').' provisioning'];
        if (filled($config['secret'] ?? null)) {
            $headers['X-Webhook-Signature'] = 'sha256='.hash_hmac('sha256', $body, $config['secret']);
        }

        $response = Http::withHeaders($headers)
            ->acceptJson()
            ->timeout(30)
            ->withBody($body, 'application/json')
            ->post($url);

        $message = is_string($response->json('message')) ? Str::limit($response->json('message'), 500) : null;

        if (! $response->successful()) {
            return ProvisioningResult::failed("The webhook answered HTTP {$response->status()}".($message ? ": {$message}" : '.'));
        }

        $data = $response->json('data');

        return ProvisioningResult::ok($message, is_array($data) && ! array_is_list($data) ? $data : []);
    }

    private function payload(Service $service, string $action, string $delivery): array
    {
        $client = $service->client;

        return [
            'action' => $action,
            'delivery' => $delivery,
            'sent_at' => now()->toIso8601String(),
            'service' => [
                'id' => $service->id,
                'label' => $service->label,
                'status' => $service->status->value,
                'product' => ['id' => $service->product->id, 'name' => $service->product->name],
                'billing_cycle' => $service->billing_cycle->value,
                'recurring_amount' => $service->recurring_amount,
                'currency' => $client->currency,
                'registration_date' => $service->registration_date?->toDateString(),
                'next_due_date' => $service->next_due_date?->toDateString(),
                'custom_fields' => $service->custom_fields ?? (object) [],
                'provisioning_data' => $service->provisioning_data ?? (object) [],
            ],
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'company' => $client->company,
                'email' => $client->email,
            ],
        ];
    }
}
