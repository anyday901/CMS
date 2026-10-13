<?php

namespace App\Provisioning\Modules;

use App\Models\AccountMove;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\CopiesAccounts;
use App\Provisioning\ModuleError;
use App\Provisioning\ProductSettings;
use App\Provisioning\ProvisioningResult;
use App\Provisioning\ReportsServerHealth;
use App\Provisioning\ReportsUsage;
use App\Provisioning\ServerAccount;
use App\Provisioning\ServerHealth;
use App\Provisioning\SingleSignOn;
use App\Provisioning\Usage;
use Illuminate\Support\Collection;

/**
 * cPanel hosting accounts, through WHM API 1 (/json-api on port 2087). Each
 * service is one cPanel account on its server, created with the product's
 * WHM package. Logs in with a WHM API token, or the password when there is
 * no token, as root or a reseller. A move to another cPanel server can copy
 * the account with WHM's transfer tool (see CpanelTransfer).
 */
class Cpanel extends ServerModule implements CopiesAccounts, ReportsServerHealth, ReportsUsage, SingleSignOn
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

    /** A one-time cPanel login from WHM's create_user_session. */
    public function loginUrl(Service $service, string $ip): string
    {
        return $this->reach($service, function (Server $server) use ($service) {
            $url = $this->whm($server, 'create_user_session', ['user' => ServerAccount::username($service), 'service' => 'cpaneld'])['url'] ?? null;

            return is_string($url) && str_starts_with($url, 'http') ? $url : throw new ModuleError('WHM didn\'t return a login address.');
        });
    }

    /** WHM's transfer tool logs in to the old server over SSH as root, with its password. */
    public function copyProblem(Server $from, Server $to): ?string
    {
        return match (true) {
            $from->username !== 'root' => "WHM's transfer tool logs in to {$from->name} as root, and {$from->name} is set up with {$from->username}.",
            blank($from->password) => "{$from->name} has no root password saved, which WHM's transfer tool logs in to it with over SSH.",
            default => null,
        };
    }

    /** Copies the old account to the new server with WHM's transfer tool, run on the new server. */
    public function copyAccount(Service $service, AccountMove $move): ProvisioningResult
    {
        return $this->onServer($service, fn (Server $server) => (new CpanelTransfer($this->api($server), $service, $move->oldAccount(), $server))->run());
    }

    /** Disk use and limits from listaccts, and this month's bandwidth from showbw: two calls for the whole server. */
    public function usage(Server $server, Collection $services): array
    {
        $api = $this->api($server);
        $disk = collect($api->call('listaccts', ['want' => 'user,diskused,disklimit'])['acct'] ?? [])->keyBy('user');
        $bandwidth = collect($api->call('showbw', ['month' => now()->month, 'year' => now()->year])['acct'] ?? [])->keyBy('user');

        return $services->filter(fn (Service $service) => $disk->has((string) $service->username))
            ->mapWithKeys(function (Service $service) use ($disk, $bandwidth) {
                $account = $disk->get($service->username);
                $traffic = $bandwidth->get($service->username, []);

                return [$service->id => new Usage(
                    diskMb: Usage::parse($account['diskused'] ?? null) ?? 0,
                    diskLimitMb: Usage::parse($account['disklimit'] ?? null),
                    bandwidthMb: Usage::fromBytes($traffic['totalbytes'] ?? null) ?? 0,
                    bandwidthLimitMb: Usage::fromBytes($traffic['limit'] ?? null),
                )];
            })->all();
    }

    /** The load averages over 1, 5 and 15 minutes, from WHM's systemloadavg. */
    public function health(Server $server): ServerHealth
    {
        $load = $this->whm($server, 'systemloadavg');

        return new ServerHealth(['Load' => implode(', ', array_map(fn ($key) => (string) ($load[$key] ?? '?'), ['one', 'five', 'fifteen']))]);
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
        return $this->api($server)->account($username);
    }

    /**
     * Calls a WHM API 1 function.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed> the reply's data
     */
    private function whm(Server $server, string $function, array $params = []): array
    {
        return $this->api($server)->call($function, $params);
    }

    private function api(Server $server): WhmApi
    {
        return new WhmApi($server, $this->defaultPort($server->secure));
    }
}
