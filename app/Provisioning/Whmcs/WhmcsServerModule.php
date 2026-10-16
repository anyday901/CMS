<?php

namespace App\Provisioning\Whmcs;

use App\Models\Server;
use App\Models\Service;
use App\Provisioning\ModuleError;
use App\Provisioning\ProvisioningResult;
use App\Provisioning\ServerAccount;
use App\Provisioning\UsesServers;

/**
 * An uploaded WHMCS module that manages accounts on servers added under
 * Products > Servers. The server's username, password and API token reach
 * the module as serverusername, serverpassword and serveraccesshash.
 */
class WhmcsServerModule extends WhmcsProvisioningModule implements UsesServers
{
    public function defaultPort(bool $secure = true): int
    {
        $ports = $this->record->meta['ports'] ?? [];

        return (int) ($secure ? ($ports['secure'] ?? 443) : ($ports['plain'] ?? 80));
    }

    public function serverFields(): array
    {
        return [
            'username' => ['help' => 'The login the WHMCS module uses on the server (serverusername).'],
            'password' => ['help' => 'Its password (serverpassword).'],
            'api_token' => ['help' => 'What WHMCS calls the access hash (serveraccesshash), when the module uses one.'],
            'ip_address' => ['help' => 'The server\'s main IP address (serverip).'],
            'assigned_ips' => ['help' => 'More IP addresses, one per line, for modules that hand them out.'],
        ];
    }

    public function testConnection(Server $server): ProvisioningResult
    {
        if (! $this->record->has('TestConnection')) {
            return ProvisioningResult::ok('The WHMCS module has no connection test, so the details weren\'t checked.');
        }

        try {
            $result = $this->call('TestConnection', Params::forServer($server, $this->defaultPort((bool) $server->secure)));
        } catch (ModuleError $e) {
            return ProvisioningResult::failed($e->getMessage());
        }

        return is_array($result) && ($result['success'] ?? false)
            ? ProvisioningResult::ok('Connected.')
            : ProvisioningResult::failed(is_array($result) && filled($result['error'] ?? null) ? (string) $result['error'] : 'The connection test failed.');
    }

    public function needsDomain(): bool
    {
        return $this->record->needs_domain;
    }

    public function loginDetails(Service $service): array
    {
        return array_filter([
            'Server' => (string) $service->server?->hostname,
            'Username' => (string) $service->username,
        ]);
    }

    protected function defaultPortFor(?Server $server): int
    {
        return $this->defaultPort((bool) ($server?->secure ?? true));
    }

    protected function server(Service $service): Server
    {
        $server = $service->server
            ?? throw new ModuleError('The service has no server. Choose one on the service page, then run the action again.');

        if ($server->module !== $this->key()) {
            throw new ModuleError("The service's server {$server->name} isn't a {$this->label()} server. Choose another on the service page.");
        }

        return $server;
    }

    protected function prepare(Service $service, ?Server $server): void
    {
        if ($service->provisioned_at !== null || $server === null) {
            return;
        }

        if ($this->needsDomain() && ! ServerAccount::isDomain((string) $service->label)) {
            throw new ModuleError('The service needs a domain name, such as example.com. Set it on the service page, then run create again.');
        }

        // WHMCS gives a new account its username and password before CreateAccount.
        ServerAccount::fillLogin($service, fn () => ServerAccount::newUsername($service, $server));
    }
}
