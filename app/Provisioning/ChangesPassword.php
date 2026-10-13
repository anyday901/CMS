<?php

namespace App\Provisioning;

use App\Models\Service;

/**
 * For modules that can set a new password on the account, such as a control
 * panel login. Called after staff, or the client in the portal, save a new
 * password, with the new one already in $service->password.
 */
interface ChangesPassword
{
    public function changePassword(Service $service): ProvisioningResult;

    /** Whether this service's password can be changed, e.g. not on a kind of account the module can't reach inside. */
    public function canChangePassword(Service $service): bool;
}
