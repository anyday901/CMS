<?php

namespace App\Provisioning\Converted;

use App\Provisioning\UsesServers;
use App\Provisioning\Whmcs\ManagesWhmcsServers;

/** A converted WHMCS module that manages accounts on servers added under Products > Servers. */
abstract class ConvertedServerModule extends ConvertedModule implements UsesServers
{
    use ManagesWhmcsServers;
}
