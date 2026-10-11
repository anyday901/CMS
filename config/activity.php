<?php

return [
    // Delete activity log entries older than this many days, every night.
    // Null keeps them forever. Can also be set on the admin Settings page.
    'retention_days' => env('ACTIVITY_RETENTION_DAYS'),
];
