<?php

namespace App\Provisioning\Modules;

use App\Models\Server;
use App\Models\Service;
use App\Provisioning\ModuleError;
use App\Provisioning\ProductSettings;
use App\Provisioning\ProvisioningResult;
use App\Provisioning\ServerAccount;

/**
 * DirectAdmin user accounts, through its CMD_API commands on port 2222. Each
 * service is one DirectAdmin user on the product's user package. Logs in as
 * admin or a reseller, with the password or a login key.
 */
class DirectAdmin extends ServerModule
{
    private const DIRECTADMIN = 'DirectAdmin';

    private const PORT = 2222;

    private const SELECT_USERS = 'CMD_API_SELECT_USERS';

    public function key(): string
    {
        return 'directadmin';
    }

    public function label(): string
    {
        return self::DIRECTADMIN;
    }

    public function configFields(): array
    {
        return [
            'package' => ['label' => 'User package', 'required' => true, 'help' => 'The user package new accounts get, named exactly as in DirectAdmin. An upgrade or downgrade moves the account to the new product\'s package.'],
        ];
    }

    public function defaultPort(bool $secure): int
    {
        return self::PORT;
    }

    public function serverFields(): array
    {
        return [
            'username' => ['help' => 'admin, or the reseller that owns the accounts.', 'required' => true],
            'password' => ['help' => 'The password, or a login key.'],
            'ip_address' => ['help' => 'The shared IP address new accounts get.', 'required' => true],
        ];
    }

    public function loginDetails(Service $service): array
    {
        return array_filter([
            'Control panel' => $service->server?->url(self::PORT),
            'Username' => $service->username,
        ]);
    }

    protected function checkConnection(Server $server): ProvisioningResult
    {
        $this->call($server, 'CMD_API_LOGIN_TEST', method: 'get');

        return ProvisioningResult::ok("Connected to DirectAdmin as {$server->username}.");
    }

    protected function createAccount(Service $service, Server $server): ProvisioningResult
    {
        $package = ProductSettings::of($service)->required('package', 'user package');
        $ip = $server->ip_address ?: throw new ModuleError("Set the IP address of the server {$server->name} first.");

        if ($this->exists($server, $service->username)) {
            // Already made, by an earlier run whose answer was lost.
            $domain = $this->call($server, 'CMD_API_SHOW_USER_CONFIG', ['user' => $service->username], 'get')['domain'] ?? '';

            if (strcasecmp($domain, (string) $service->label) === 0) {
                return ProvisioningResult::ok("The user {$service->username} was already on {$server->name}.");
            }

            $owner = $domain !== '' ? " by {$domain}" : '';

            return ProvisioningResult::failed("The username {$service->username} is already used on {$server->name}{$owner}. Enter another username on the service page, then run create again.");
        }

        $this->call($server, 'CMD_API_ACCOUNT_USER', [
            'action' => 'create',
            'add' => 'Submit',
            'username' => $service->username,
            'email' => $service->client->email,
            'passwd' => $service->password,
            'passwd2' => $service->password,
            'domain' => $service->label,
            'package' => $package,
            'ip' => $ip,
            'notify' => 'no',
        ]);

        return ProvisioningResult::ok("Created the user {$service->username} on {$server->name}.");
    }

    protected function suspendAccount(Service $service, Server $server): ProvisioningResult
    {
        $username = ServerAccount::username($service);
        // Newer DirectAdmin reads dosuspend, older reads suspend=Suspend.
        $this->call($server, self::SELECT_USERS, ['location' => 'CMD_SELECT_USERS', 'suspend' => 'Suspend', 'dosuspend' => 'yes', 'select0' => $username]);

        return ProvisioningResult::ok("Suspended {$username}.");
    }

    protected function unsuspendAccount(Service $service, Server $server): ProvisioningResult
    {
        $username = ServerAccount::username($service);
        $this->call($server, self::SELECT_USERS, ['location' => 'CMD_SELECT_USERS', 'suspend' => 'Unsuspend', 'dounsuspend' => 'yes', 'select0' => $username]);

        return ProvisioningResult::ok("Unsuspended {$username}.");
    }

    protected function terminateAccount(Service $service, Server $server): ProvisioningResult
    {
        $username = ServerAccount::username($service);

        if (! $this->exists($server, $username)) {
            return ProvisioningResult::ok("The user {$username} was already gone from {$server->name}.");
        }

        $this->call($server, self::SELECT_USERS, ['confirmed' => 'Confirm', 'delete' => 'yes', 'select0' => $username]);

        return ProvisioningResult::ok("Removed the user {$username} from {$server->name}.");
    }

    protected function changeAccountPackage(Service $service, Server $server): ProvisioningResult
    {
        $username = ServerAccount::username($service);
        $package = ProductSettings::of($service)->required('package', 'user package');
        $this->call($server, 'CMD_API_MODIFY_USER', ['action' => 'package', 'user' => $username, 'package' => $package]);

        return ProvisioningResult::ok("Moved {$username} to the package {$package}.");
    }

    protected function changeAccountPassword(Service $service, Server $server): ProvisioningResult
    {
        $username = ServerAccount::username($service);
        $this->call($server, 'CMD_API_USER_PASSWD', ['username' => $username, 'passwd' => $service->password, 'passwd2' => $service->password]);

        return ProvisioningResult::ok("Changed the password of {$username}.");
    }

    /** Whether the login's list of users has $username. */
    private function exists(Server $server, string $username): bool
    {
        return in_array($username, (array) ($this->call($server, 'CMD_API_SHOW_USERS', method: 'get')['list'] ?? []), true);
    }

    /**
     * Sends a command to the server.
     *
     * @param  array<string, string>  $params
     * @return array<string, mixed>
     */
    private function call(Server $server, string $command, array $params = [], string $method = 'post'): array
    {
        return (new DirectAdminApi($server, self::PORT))->call($command, $params, $method);
    }
}
