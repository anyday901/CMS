<?php

namespace App\Hooks;

/** Something that sees every event, such as outgoing webhooks. Listed in config/integrations.php. */
interface HookSubscriber
{
    public function handle(HookEvent $event): void;
}
