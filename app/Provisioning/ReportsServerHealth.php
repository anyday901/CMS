<?php

namespace App\Provisioning;

use App\Models\Server;

/**
 * For modules whose servers report their own state, such as their load or
 * which of their nodes are online. Server monitoring asks after checking
 * the server's ports.
 */
interface ReportsServerHealth
{
    public function health(Server $server): ServerHealth;
}
