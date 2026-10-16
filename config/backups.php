<?php

return [
    // Local folder the database backups are written to.
    'path' => env('BACKUPS_PATH', storage_path('app/backups')),

    // Automatic backups: "off", "daily" or "weekly" (Sundays). Changed under Settings > Backups.
    'schedule' => env('BACKUPS_SCHEDULE', 'daily'),

    // The time of day automatic backups run, "HH:MM".
    'time' => env('BACKUPS_TIME', '01:30'),

    // How many backups to keep. Older ones are deleted after each new one.
    'keep' => (int) env('BACKUPS_KEEP', 14),

    // Also copy each backup to this filesystem disk (config/filesystems.php), such as an S3 bucket. Blank keeps them on this server only.
    'disk' => env('BACKUPS_DISK'),
];
