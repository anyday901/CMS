<?php

namespace App\Provisioning\Whmcs;

use App\Models\Server;
use App\Models\Service;
use App\Provisioning\ModuleError;
use App\Provisioning\ProvisioningResult;
use App\Provisioning\ServerAccount;

/**
 * The server side of a WHMCS-style module (see UsesServers): the server's
 * username, password and API token reach the module as serverusername,
 * serverpassword and serveraccesshash.
 */
trait ManagesWhmcsServers
{
    public function serverFields(): array
    {
        return [
            'username' => ['help' => 'The login the module uses on the server (serverusername).'],
            'password' => ['help' => 'Its password (serverpassword).'],
            'api_token' => ['help' => 'What WHMCS calls the access hash (serveraccesshash), when the module uses one.'],
            'ip_address' => ['help' => 'The server\'s main IP address (serverip).'],
            'assigned_ips' => ['help' => 'More IP addresses, one per line, for modules that hand them out.'],
        ];
    }

    public function testConnection(Server $server): ProvisioningResult
    {
        if (! $this->hasFunction('TestConnection')) {
            return ProvisioningResult::ok('The module has no connection test, so the details weren\'t checked.');
        }

        try {
            $result = $this->call('TestConnection', Params::forServer($server, $this->defaultPortFor($server)));
            $connected = is_array($result) && ($result['success'] ?? false);
            $message = $connected ? 'Connected.' : self::message($result, 'error', 'The connection test failed.');
        } catch (ModuleError $e) {
            $connected = false;
            $message = $e->getMessage();
        }

        return $connected ? ProvisioningResult::ok($message) : ProvisioningResult::failed($message);
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

    /** The address ServiceSingleSignOn returns, for SingleSignOn::loginUrl(). @throws ModuleError */
    protected function singleSignOnUrl(Service $service): string
    {
        $result = $this->call('ServiceSingleSignOn', $this->params($service, $this->server($service)));
        $url = is_array($result) && ($result['success'] ?? false) ? ($result['redirectTo'] ?? null) : null;

        return is_string($url) && str_starts_with($url, 'http')
            ? $url
            : throw new ModuleError(self::message($result, 'errorMsg', 'The module didn\'t return a login address.'));
    }

    /** The error message a WHMCS function returned under the key, or the fallback. */
    private static function message(mixed $result, string $key, string $fallback): string
    {
        $message = is_array($result) ? ($result[$key] ?? null) : null;

        return filled($message) ? (string) $message : $fallback;
    }
}
