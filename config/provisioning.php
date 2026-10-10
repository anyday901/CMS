<?php

return [
    // Provisioning modules staff can choose on a product. Each class
    // implements App\Provisioning\ProvisioningModule. Actions run on the
    // queue, so run a queue worker (php artisan queue:work) in production.
    'modules' => [
        // App\Provisioning\Modules\MyPanel::class,
    ],
];
