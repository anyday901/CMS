<?php

namespace App\Provisioning;

use App\Models\AccountMove;
use App\Models\Server;
use App\Models\Service;

/**
 * For modules that can copy an account to another of their servers
 * themselves, such as a cPanel transfer or a Proxmox migration, so staff
 * don't copy the data by hand when moving a service to another server.
 */
interface CopiesAccounts
{
    /**
     * Why the module can't copy an account from $from to $to, or null when it
     * can. Only looks at the servers' saved details, without calling them.
     */
    public function copyProblem(Server $from, Server $to): ?string;

    /**
     * Makes the service's account on its new server ($service->server) as a
     * copy of the move's old account ($move->oldAccount()), in place of
     * create. It runs as the copy_account action, so return
     * ProvisioningResult::later() while the copy runs, and make each run safe
     * to repeat. When the copy takes the old account with it, as a VM
     * migrated inside one Proxmox cluster does, set the move's remove_old to
     * false before starting, so finishing the move never removes the account.
     */
    public function copyAccount(Service $service, AccountMove $move): ProvisioningResult;
}
