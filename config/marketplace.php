<?php

return [
    // The registry of add-ons and themes listed under Settings > Marketplace.
    'registry_url' => env('MARKETPLACE_REGISTRY_URL', 'https://anyday901.github.io/CMS/marketplace/registry.json'),

    // Set to false to only browse the marketplace, never install from it or from an uploaded package.
    'installs' => (bool) env('MARKETPLACE_INSTALLS', true),

    // Where add-ons from the marketplace are unpacked, one folder each.
    'addons_path' => env('MARKETPLACE_ADDONS_PATH', base_path('extensions/addons')),
];
