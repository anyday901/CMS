<?php

use App\Addons\ProjectManagement\ProjectManagementAddon;

return [
    // Add-on modules staff can install under Settings > Add-ons. Each class
    // extends App\Addons\Addon (see docs/addons.md). A Composer package can
    // add its own from a service provider with AddonManager::register().
    'available' => [
        ProjectManagementAddon::class,
    ],
];
