<?php

namespace App\Provisioning;

use App\Models\Service;

/**
 * For modules that can move a service to another product, such as a bigger
 * hosting plan. Called after an upgrade or downgrade is applied, with the
 * service already on its new product and options. Modules without it are
 * left alone when a service changes product.
 */
interface ChangesPackage
{
    public function changePackage(Service $service): ProvisioningResult;
}
