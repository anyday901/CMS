<?php

namespace App\Provisioning;

use App\Models\Service;

/**
 * For modules that can wipe an account and set it up again from scratch,
 * such as a VM rebuilt from its template. Run by staff, or by the client
 * from the portal, on an active service. Everything on the account is lost.
 */
interface Reinstalls
{
    public function reinstall(Service $service): ProvisioningResult;
}
