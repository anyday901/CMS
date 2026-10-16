<?php

return [
    /*
     * Maintenance mode, turned on under Settings > Maintenance. Visitors to
     * the portal, store and help pages see the message instead; the admin
     * area, the API, payment webhooks and ticket email keep working, and
     * signed-in staff can still look at the portal.
     */
    'enabled' => (bool) env('MAINTENANCE_MODE', false),

    'message' => env('MAINTENANCE_MESSAGE'),
];
