<?php

namespace App\Provisioning;

use App\Models\Product;

/**
 * For modules with products for different kinds of account (a Proxmox VM
 * and a container), where changing between them means a new account, not
 * a package change.
 */
interface SplitsAccountKinds
{
    /** Whether a service moving from $from to $to, both of this module, needs a new account. */
    public function needsNewAccount(Product $from, Product $to): bool;
}
