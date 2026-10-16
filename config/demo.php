<?php

return [
    /*
     * Demo mode, for a public demo instance. It shows the demo logins on the
     * login pages, blocks changes that could break the demo or leak anything
     * (mail settings, staff accounts, backups, updates, the marketplace and
     * maintenance mode), never sends email, and resets the data every few
     * hours with `php artisan demo:reset`.
     */
    'enabled' => (bool) env('DEMO_MODE', false),

    // Hours between automatic resets. 0 turns them off.
    'reset_hours' => (int) env('DEMO_RESET_HOURS', 3),

    'staff_email' => env('DEMO_STAFF_EMAIL', 'demo@example.com'),

    'client_email' => env('DEMO_CLIENT_EMAIL', 'client@example.com'),

    'password' => env('DEMO_PASSWORD', 'demo'),
];
