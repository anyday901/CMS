<?php

use App\Provisioning\Modules\ManualFulfillment;
use App\Provisioning\Modules\Webhook;

return [
    // Provisioning modules staff can choose on a product. Each class
    // implements App\Provisioning\ProvisioningModule. Actions run on the
    // queue, so run a queue worker (php artisan queue:work) in production.
    'modules' => [
        ManualFulfillment::class,
        Webhook::class,
        // App\Provisioning\Modules\MyPanel::class,
    ],
];
