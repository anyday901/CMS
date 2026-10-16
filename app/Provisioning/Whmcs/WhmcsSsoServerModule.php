<?php

namespace App\Provisioning\Whmcs;

use App\Models\Service;
use App\Provisioning\ModuleError;
use App\Provisioning\SingleSignOn;

/** A WHMCS server module with ServiceSingleSignOn, which gives clients a log in button. */
class WhmcsSsoServerModule extends WhmcsServerModule implements SingleSignOn
{
    public function loginUrl(Service $service, string $ip): string
    {
        $server = $this->server($service);
        $result = $this->call('ServiceSingleSignOn', Params::forService($this->record, $service, $server, $this->defaultPortFor($server)));
        $url = is_array($result) && ($result['success'] ?? false) ? ($result['redirectTo'] ?? null) : null;

        return is_string($url) && str_starts_with($url, 'http')
            ? $url
            : throw new ModuleError(is_array($result) && filled($result['errorMsg'] ?? null) ? (string) $result['errorMsg'] : 'The WHMCS module didn\'t return a login address.');
    }
}
