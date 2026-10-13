<?php

namespace App\Provisioning;

use App\Models\Service;

/**
 * For modules that can set a new password on the account, such as a control
 * panel login. Called after staff save a new password on the service page,
 * with the new one already in $service->password.
 */
interface ChangesPassword
{
    public function changePassword(Service $service): ProvisioningResult;
}
