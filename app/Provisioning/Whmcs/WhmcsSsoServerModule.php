<?php

namespace App\Provisioning\Whmcs;

use App\Models\Service;
use App\Provisioning\SingleSignOn;

/** A WHMCS server module with ServiceSingleSignOn, which gives clients a log in button. */
class WhmcsSsoServerModule extends WhmcsServerModule implements SingleSignOn
{
    public function loginUrl(Service $service, string $ip): string
    {
        return $this->singleSignOnUrl($service);
    }
}
