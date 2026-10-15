<?php

return [
    // The GitHub repository releases come from, as owner/name.
    'repository' => env('UPDATES_REPOSITORY', 'anyday901/CMS'),

    // Releases API. Kept as a setting so a fork can point at its own.
    'api_url' => env('UPDATES_API_URL', 'https://api.github.com'),

    // The release asset the updater downloads, and the checksum file beside it.
    'asset' => env('UPDATES_ASSET', 'cms.zip'),

    // Set to false to hide the updater, such as when you deploy with your own tooling.
    'enabled' => (bool) env('UPDATES_ENABLED', true),
];
