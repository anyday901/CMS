<?php

namespace App\Provisioning\Modules;

use App\Models\Server;
use App\Models\Service;
use App\Provisioning\ChangesPackage;
use App\Provisioning\ChangesPassword;
use App\Provisioning\ModuleError;
use App\Provisioning\ProvisioningModule;
use App\Provisioning\ProvisioningResult;
use App\Provisioning\UsesServers;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Shared ground for modules that manage accounts on a server: every action
 * runs against the service's server, a new service gets a username and
 * password before it is created, and errors from the server become the
 * action's failure message for staff.
 */
abstract class ServerModule implements ChangesPackage, ChangesPassword, ProvisioningModule, UsesServers
{
    /** Seconds to wait for an answer; creating an account can be slow. */
    protected const TIMEOUT = 50;

    /** Characters for new passwords; each kind is used at least once. */
    private const PASSWORD_SETS = ['abcdefghijkmnopqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '!@*-_.'];

    private const PASSWORD_LENGTH = 16;

    private const USERNAME_LENGTH = 8;

    abstract protected function createAccount(Service $service, Server $server): ProvisioningResult;

    abstract protected function suspendAccount(Service $service, Server $server): ProvisioningResult;

    abstract protected function unsuspendAccount(Service $service, Server $server): ProvisioningResult;

    abstract protected function terminateAccount(Service $service, Server $server): ProvisioningResult;

    abstract protected function changeAccountPackage(Service $service, Server $server): ProvisioningResult;

    abstract protected function changeAccountPassword(Service $service, Server $server): ProvisioningResult;

    abstract protected function checkConnection(Server $server): ProvisioningResult;

    public function create(Service $service): ProvisioningResult
    {
        return $this->onServer($service, function (Server $server) use ($service) {
            if ($this->needsDomain() && ! self::isDomain((string) $service->label)) {
                return ProvisioningResult::failed('The service needs a domain name, such as example.com. Set it on the service page, then run create again.');
            }

            $this->fillLogin($service, $server);

            return $this->createAccount($service, $server);
        });
    }

    public function suspend(Service $service): ProvisioningResult
    {
        return $this->onServer($service, fn (Server $server) => $this->suspendAccount($service, $server));
    }

    public function unsuspend(Service $service): ProvisioningResult
    {
        return $this->onServer($service, fn (Server $server) => $this->unsuspendAccount($service, $server));
    }

    public function terminate(Service $service): ProvisioningResult
    {
        return $this->onServer($service, fn (Server $server) => $this->terminateAccount($service, $server));
    }

    public function changePackage(Service $service): ProvisioningResult
    {
        return $this->onServer($service, fn (Server $server) => $this->changeAccountPackage($service, $server));
    }

    public function changePassword(Service $service): ProvisioningResult
    {
        return $this->onServer($service, function (Server $server) use ($service) {
            if (blank($service->password)) {
                return ProvisioningResult::failed('The service has no password to set. Enter one on the service page.');
            }

            return $this->changeAccountPassword($service, $server);
        });
    }

    public function testConnection(Server $server): ProvisioningResult
    {
        return $this->attempt($server, fn () => $this->checkConnection($server));
    }

    public function needsDomain(): bool
    {
        return true;
    }

    /** Whether $value looks like a domain name with at least one dot. */
    public static function isDomain(string $value): bool
    {
        return str_contains($value, '.') && filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    /** A validation rule for a service's domain name. */
    public static function domainRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! self::isDomain((string) $value)) {
                $fail('Enter the domain name, such as example.com.');
            }
        };
    }

    /** A random password with lower and upper case letters, digits and a symbol. */
    public static function newPassword(): string
    {
        $pick = fn (string $set) => $set[random_int(0, strlen($set) - 1)];
        $chars = array_map($pick, self::PASSWORD_SETS);
        $all = implode('', self::PASSWORD_SETS);

        while (count($chars) < self::PASSWORD_LENGTH) {
            $chars[] = $pick($all);
        }

        return implode('', Arr::shuffle($chars));
    }

    /** An HTTP client for the server's API, at its address, port and certificate setting. */
    protected function http(Server $server, int $timeout = self::TIMEOUT): PendingRequest
    {
        return Http::baseUrl($server->url($this->defaultPort($server->secure)))
            ->withOptions(['verify' => $server->verify_tls])
            ->withUserAgent(config('app.name').' provisioning')
            ->connectTimeout(10)
            ->timeout($timeout);
    }

    /** The error for an HTTP answer that isn't a usable reply from $system to $what. */
    protected function unexpected(string $system, Response $response, string $what): ModuleError
    {
        $status = $response->status();

        return new ModuleError(in_array($status, [401, 403], true)
            ? "{$system} refused the login (HTTP {$status}). Check the server's username, password and API token."
            : "Unexpected answer from {$system} to {$what} (HTTP {$status}).");
    }

    /** A product setting, trimmed, or null when it isn't set. */
    protected function setting(Service $service, string $key): ?string
    {
        $value = trim((string) ($service->product->module_config[$key] ?? ''));

        return $value === '' ? null : $value;
    }

    /** A product setting that must be set; the error names it as $label. */
    protected function requiredSetting(Service $service, string $key, string $label): string
    {
        return $this->setting($service, $key)
            ?? throw new ModuleError("Set the {$label} on the product {$service->product->name} first.");
    }

    /** The account's username on the server. */
    protected function username(Service $service): string
    {
        return filled($service->username)
            ? $service->username
            : throw new ModuleError('The service has no username on the server. Run create first, or enter the username on the service page.');
    }

    /** Gives a new service a username and password unless staff already set them. */
    protected function fillLogin(Service $service, Server $server): void
    {
        $service->forceFill([
            'username' => filled($service->username) ? $service->username : $this->newUsername($service, $server),
            'password' => filled($service->password) ? $service->password : self::newPassword(),
        ]);

        if ($service->isDirty()) {
            $service->save();
        }
    }

    /**
     * Up to 8 lower case letters and digits from the domain (or the client's
     * name), starting with a letter, and not used by another service on the
     * same server. Control panels refuse names starting with "test".
     */
    protected function newUsername(Service $service, Server $server): string
    {
        $source = Str::before((string) $service->label, '.') ?: $service->client->first_name.$service->client->last_name;
        $base = ltrim(preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($source))), '0123456789');
        $base = Str::startsWith($base, 'test') ? 'u'.$base : $base;
        $base = substr(str_pad($base, 3, 'x'), 0, self::USERNAME_LENGTH);

        $taken = $server->services()->whereKeyNot($service->id)->whereNotNull('username')->pluck('username');
        $username = $base;

        for ($tries = 0; $taken->contains($username) && $tries < 50; $tries++) {
            $username = substr($base, 0, self::USERNAME_LENGTH - 3).random_int(100, 999);
        }

        return $username;
    }

    /**
     * Runs an action on the service's server, which must be one of this
     * module's. Errors the server reports and failed connections become the
     * action's failure.
     *
     * @param  Closure(Server): ProvisioningResult  $action
     */
    private function onServer(Service $service, Closure $action): ProvisioningResult
    {
        $server = $service->server;

        if ($server === null) {
            return ProvisioningResult::failed('The service has no server. Choose one on the service page, then run the action again.');
        }

        if ($server->module !== $this->key()) {
            return ProvisioningResult::failed("The service's server {$server->name} isn't a {$this->label()} server. Choose another on the service page.");
        }

        return $this->attempt($server, fn () => $action($server));
    }

    /** @param  Closure(): ProvisioningResult  $action */
    private function attempt(Server $server, Closure $action): ProvisioningResult
    {
        try {
            return $action();
        } catch (ModuleError $e) {
            return ProvisioningResult::failed($e->getMessage());
        } catch (ConnectionException $e) {
            return ProvisioningResult::failed("Couldn't reach {$server->hostname}: {$e->getMessage()}");
        }
    }
}
