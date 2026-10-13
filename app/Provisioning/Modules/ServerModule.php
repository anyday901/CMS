<?php

namespace App\Provisioning\Modules;

use App\Models\Server;
use App\Models\Service;
use App\Provisioning\ChangesPackage;
use App\Provisioning\ChangesPassword;
use App\Provisioning\ModuleError;
use App\Provisioning\ProvisioningModule;
use App\Provisioning\ProvisioningResult;
use App\Provisioning\ServerAccount;
use App\Provisioning\UsesServers;
use Closure;
use Illuminate\Http\Client\ConnectionException;

/**
 * Shared ground for modules that manage accounts on a server: every action
 * runs against the service's server, a new service gets a username and
 * password before it is created, and errors from the server become the
 * action's failure message for staff.
 */
abstract class ServerModule implements ChangesPackage, ChangesPassword, ProvisioningModule, UsesServers
{
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
            if ($this->needsDomain() && ! ServerAccount::isDomain((string) $service->label)) {
                return ProvisioningResult::failed('The service needs a domain name, such as example.com. Set it on the service page, then run create again.');
            }

            ServerAccount::fillLogin($service, fn () => $this->newUsername($service, $server));

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

    public function canChangePassword(Service $service): bool
    {
        return true;
    }

    public function testConnection(Server $server): ProvisioningResult
    {
        return $this->attempt($server, fn () => $this->checkConnection($server));
    }

    public function needsDomain(): bool
    {
        return true;
    }

    /** The username a new account gets: one made from the domain, unused on the server. */
    protected function newUsername(Service $service, Server $server): string
    {
        return ServerAccount::newUsername($service, $server);
    }

    /**
     * Runs an action on the service's server, which must be one of this
     * module's. Errors the server reports and failed connections become the
     * action's failure.
     *
     * @param  Closure(Server): ProvisioningResult  $action
     */
    protected function onServer(Service $service, Closure $action): ProvisioningResult
    {
        try {
            return $this->reach($service, $action);
        } catch (ModuleError $e) {
            return ProvisioningResult::failed($e->getMessage());
        }
    }

    /**
     * Runs $action on the service's server and returns what it returns. A
     * missing or wrong server, or a failed connection, is a ModuleError.
     *
     * @template T
     *
     * @param  Closure(Server): T  $action
     * @return T
     */
    protected function reach(Service $service, Closure $action): mixed
    {
        $server = $service->server
            ?? throw new ModuleError('The service has no server. Choose one on the service page, then run the action again.');

        if ($server->module !== $this->key()) {
            throw new ModuleError("The service's server {$server->name} isn't a {$this->label()} server. Choose another on the service page.");
        }

        try {
            return $action($server);
        } catch (ConnectionException $e) {
            throw new ModuleError("Couldn't reach {$server->hostname}: {$e->getMessage()}", previous: $e);
        }
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
