<?php

namespace App\Provisioning;

use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Collection;

/**
 * For modules whose servers report each account's disk and bandwidth use.
 * php artisan usage:collect reads it every hour and keeps it per month, for
 * the service pages and for overage billing.
 */
interface ReportsUsage
{
    /**
     * The use of the services' accounts on $server, read in as few calls as
     * the server allows.
     *
     * @param  Collection<int, Service>  $services  services with an account on $server
     * @return array<int, Usage> by service id; an account the server doesn't list is left out
     */
    public function usage(Server $server, Collection $services): array;
}
