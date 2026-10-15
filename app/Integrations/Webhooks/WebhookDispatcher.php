<?php

namespace App\Integrations\Webhooks;

use App\Hooks\HookEvent;
use App\Hooks\HookSubscriber;
use App\Jobs\DeliverWebhook;
use App\Models\Webhook;
use App\Models\WebhookDelivery;

/** Queues a delivery of each event to every active webhook subscribed to it. */
class WebhookDispatcher implements HookSubscriber
{
    public function handle(HookEvent $event): void
    {
        Webhook::for($event->key)->each(fn (Webhook $webhook) => $this->queue($webhook, $event));
    }

    public function queue(Webhook $webhook, HookEvent $event): WebhookDelivery
    {
        $delivery = $webhook->deliveries()->create([
            'event_id' => $event->id,
            'event' => $event->key,
            'payload' => $event->toArray(),
            'status' => WebhookDelivery::PENDING,
        ]);

        DeliverWebhook::dispatch($delivery->id);

        return $delivery;
    }
}
