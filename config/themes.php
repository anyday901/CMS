<?php

return [
    // The portal theme, by key: "harbor" (built in) or a folder under themes/. Changed under Settings > Appearance.
    'active' => env('THEME', 'harbor'),

    // Where themes live. Each is a folder with a theme.json; themes/overrides/views holds this site's own template overrides.
    'path' => env('THEMES_PATH', base_path('themes')),
];
