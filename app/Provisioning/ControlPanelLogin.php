<?php

namespace App\Provisioning;

use App\Models\Service;

/**
 * Asks a module for a logged-in control panel address and checks it points
 * at the service's own server before anyone is sent there.
 */
class ControlPanelLogin
{
    private const SCHEMES = ['https', 'http'];

    /** @throws ModuleError when the panel refuses, or answers with an address on another host */
    public function url(Service $service, SingleSignOn $module, ?string $clientIp): string
    {
        $ip = filter_var($clientIp, FILTER_VALIDATE_IP);
        $url = $module->loginUrl($service, $ip === false ? '' : $ip);

        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, self::SCHEMES, true) || ! in_array($host, $this->hosts($service), true)) {
            throw new ModuleError("The control panel answered with a login address on another host ({$host}), so it wasn't opened. Check the server's hostname matches the panel's.");
        }

        return $url;
    }

    /** @return list<string> */
    private function hosts(Service $service): array
    {
        $server = $service->server;

        return array_values(array_filter(array_map(
            fn (?string $host) => $host === null ? '' : strtolower(trim($host, '[]')),
            [$server?->hostname, $server?->ip_address],
        )));
    }
}
