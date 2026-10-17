<?php

namespace App\Provisioning\Whmcs;

use App\Provisioning\UsesServers;

/** An uploaded WHMCS module that manages accounts on servers added under Products > Servers. */
class WhmcsServerModule extends WhmcsProvisioningModule implements UsesServers
{
    use ManagesWhmcsServers;

    public function defaultPort(bool $secure = true): int
    {
        $ports = $this->record->meta['ports'] ?? [];

        return (int) ($secure ? ($ports['secure'] ?? 443) : ($ports['plain'] ?? 80));
    }

    public function needsDomain(): bool
    {
        return $this->record->needs_domain;
    }
}
