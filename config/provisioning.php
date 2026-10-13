<?php

use App\Provisioning\Modules\Cpanel;
use App\Provisioning\Modules\DirectAdmin;
use App\Provisioning\Modules\ManualFulfillment;
use App\Provisioning\Modules\Plesk;
use App\Provisioning\Modules\Proxmox;
use App\Provisioning\Modules\Webhook;

return [
    // Provisioning modules staff can choose on a product. Each class
    // implements App\Provisioning\ProvisioningModule. Actions run on the
    // queue, so run a queue worker (php artisan queue:work) in production.
    // cPanel, Plesk, DirectAdmin and Proxmox work on servers added under
    // Products > Servers.
    'modules' => [
        ManualFulfillment::class,
        Webhook::class,
        Cpanel::class,
        Plesk::class,
        DirectAdmin::class,
        Proxmox::class,
        // App\Provisioning\Modules\MyPanel::class,
    ],
];
