<?php

namespace App\Integrations\Chat;

use App\Hooks\HookEvent;
use App\Hooks\HookSubscriber;
use App\Jobs\SendChatMessage;
use App\Models\NotificationChannel;

/** Posts each event to the Slack, Discord and Teams channels that want it. */
class ChatNotifier implements HookSubscriber
{
    public function handle(HookEvent $event): void
    {
        NotificationChannel::for($event->key)->pluck('id')
            ->each(fn (int $id) => SendChatMessage::dispatch($id, $event->summary, $event->url));
    }
}
