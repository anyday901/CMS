<?php

namespace App\Provisioning;

use App\Models\Service;

/**
 * For modules whose control panel can log a client straight in, from a
 * button in the portal, without typing the account's password.
 */
interface SingleSignOn
{
    /**
     * A one-time address that opens the control panel logged in as the
     * service's account. Called when the client asks, not on the queue.
     *
     * @param  string  $ip  the client's IP address, for panels that tie the session to it
     *
     * @throws ModuleError when the panel refuses or can't be reached
     */
    public function loginUrl(Service $service, string $ip): string;
}
