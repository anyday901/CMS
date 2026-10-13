<?php

namespace App\Provisioning\Modules;

use App\Models\Server;
use App\Models\Service;
use App\Provisioning\ProductSettings;
use App\Provisioning\ProvisioningResult;
use App\Provisioning\ServerAccount;

/**
 * cPanel hosting accounts, through WHM API 1 (/json-api on port 2087). Each
 * service is one cPanel account on its server, created with the product's
 * WHM package. Logs in with a WHM API token, or the password when there is
 * no token, as root or a reseller.
 */
class Cpanel extends ServerModule
{
    private const PACKAGE = 'WHM package';

    public function key(): string
    {
        return 'cpanel';
    }

    public function label(): string
    {
        return 'cPanel / WHM';
    }

    public function configFields(): array
    {
        return [
            'package' => ['label' => self::PACKAGE, 'required' => true, 'help' => 'The package new accounts get, named exactly as in WHM, e.g. root_basic. An upgrade or downgrade moves the account to the new product\'s package.'],
        ];
    }

    public function defaultPort(bool $secure): int
    {
        return $secure ? 2087 : 2086;
    }

    public function serverFields(): array
    {
        return [
            'username' => ['help' => 'root, or the reseller that owns the accounts.', 'required' => true],
            'api_token' => ['help' => 'An API token from WHM > Development > Manage API Tokens. Recommended over the password.'],
            'password' => ['help' => 'Only needed without an API token.'],
        ];
    }

    public function loginDetails(Service $service): array
    {
        return array_filter([
            'Control panel' => $service->server?->address($service->server->secure ? 2083 : 2082),
            'Username' => $service->username,
        ]);
    }

    protected function checkConnection(Server $server): ProvisioningResult
    {
        $version = $this->whm($server, 'version')['version'] ?? null;

        return ProvisioningResult::ok('Connected to WHM'.($version ? " {$version}" : '').'.');
    }

    protected function createAccount(Service $service, Server $server): ProvisioningResult
    {
        $package = ProductSettings::of($service)->required('package', self::PACKAGE);
        $existing = $this->account($server, $service->username);

        if ($existing !== null) {
            // Already made, by an earlier run whose answer was lost.
            return strcasecmp((string) ($existing['domain'] ?? ''), (string) $service->label) === 0
                ? ProvisioningResult::ok("The account {$service->username} was already on {$server->name}.")
                : ProvisioningResult::failed("The username {$service->username} is already used on {$server->name} by {$existing['domain']}. Enter another username on the service page, then run create again.");
        }

        $this->whm($server, 'createacct', [
            'username' => $service->username,
            'domain' => $service->label,
            'plan' => $package,
            'password' => $service->password,
            'contactemail' => $service->client->email,
        ]);

        return ProvisioningResult::ok("Created the account {$service->username} on {$server->name}.");
    }

    protected function suspendAccount(Service $service, Server $server): ProvisioningResult
    {
        $username = ServerAccount::username($service);
        $this->whm($server, 'suspendacct', [
            'user' => $username,
            'reason' => 'Suspended by '.config('app.name').($service->suspension_reason ? " ({$service->suspension_reason})" : ''),
        ]);

        return ProvisioningResult::ok("Suspended {$username}.");
    }

    protected function unsuspendAccount(Service $service, Server $server): ProvisioningResult
    {
        $username = ServerAccount::username($service);
        $this->whm($server, 'unsuspendacct', ['user' => $username]);

        return ProvisioningResult::ok("Unsuspended {$username}.");
    }

    protected function terminateAccount(Service $service, Server $server): ProvisioningResult
    {
        $username = ServerAccount::username($service);

        if ($this->account($server, $username) === null) {
            return ProvisioningResult::ok("The account {$username} was already gone from {$server->name}.");
        }

        $this->whm($server, 'removeacct', ['username' => $username]);

        return ProvisioningResult::ok("Removed the account {$username} from {$server->name}.");
    }

    protected function changeAccountPackage(Service $service, Server $server): ProvisioningResult
    {
        $username = ServerAccount::username($service);
        $package = ProductSettings::of($service)->required('package', self::PACKAGE);
        $this->whm($server, 'changepackage', ['user' => $username, 'pkg' => $package]);

        return ProvisioningResult::ok("Moved {$username} to the package {$package}.");
    }

    protected function changeAccountPassword(Service $service, Server $server): ProvisioningResult
    {
        $username = ServerAccount::username($service);
        $this->whm($server, 'passwd', ['user' => $username, 'password' => $service->password]);

        return ProvisioningResult::ok("Changed the password of {$username}.");
    }

    /** @return array<string, mixed>|null the account as WHM lists it, or null when the server has none by that name */
    private function account(Server $server, string $username): ?array
    {
        $accounts = $this->whm($server, 'listaccts', ['search' => $username, 'searchtype' => 'user', 'searchmethod' => 'exact', 'want' => 'user,domain,suspended,plan']);

        return collect($accounts['acct'] ?? [])->first(fn (array $account) => ($account['user'] ?? null) === $username);
    }

    /**
     * Calls a WHM API 1 function.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed> the reply's data
     */
    private function whm(Server $server, string $function, array $params = []): array
    {
        return (new WhmApi($server, $this->defaultPort($server->secure)))->call($function, $params);
    }
}
