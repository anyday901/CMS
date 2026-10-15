<?php

use App\Integrations\Chat\ChatNotifier;
use App\Integrations\Sms\SmsNotifier;
use App\Integrations\Webhooks\WebhookDispatcher;

return [
    // Subscribers see every event fired through App\Hooks\Hooks. Each class
    // implements App\Hooks\HookSubscriber.
    'subscribers' => [
        WebhookDispatcher::class,
        ChatNotifier::class,
        SmsNotifier::class,
    ],
];
