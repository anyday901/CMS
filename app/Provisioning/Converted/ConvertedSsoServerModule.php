<?php

namespace App\Provisioning\Converted;

use App\Models\Service;
use App\Provisioning\SingleSignOn;

/** A converted WHMCS server module with a singleSignOn method, which gives clients a log in button. */
abstract class ConvertedSsoServerModule extends ConvertedServerModule implements SingleSignOn
{
    public function loginUrl(Service $service, string $ip): string
    {
        return $this->singleSignOnUrl($service);
    }
}
