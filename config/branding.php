<?php

return [
    /*
     * White-label settings, changed under Settings > Appearance. The company
     * name, address, email and tax ID live in config/billing.php, since
     * invoices print them too.
     */

    // Path of the uploaded logo on the public disk, shown in the admin area, the portal, emails and PDF invoices.
    'logo' => env('BRANDING_LOGO'),

    // One hex colour for buttons and links. It replaces the theme's brand colour; the lighter and darker shades are worked out from it.
    'color' => env('BRANDING_COLOR'),

    // What the admin area calls itself, in its menu and browser tab. Blank uses the company name.
    'admin_name' => env('BRANDING_ADMIN_NAME'),

    // Hide the small "Powered by CMS" line at the foot of the portal.
    'hide_powered_by' => (bool) env('BRANDING_HIDE_POWERED_BY', false),
];
