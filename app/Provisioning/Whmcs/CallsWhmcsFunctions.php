<?php

namespace App\Provisioning\Whmcs;

use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\ModuleError;
use App\Provisioning\ProvisioningResult;
use Throwable;

/**
 * Runs provisioning actions as WHMCS module functions: each action calls the
 * matching function with the $params WHMCS would pass, and its "success" or
 * error message becomes the result. Shared by uploaded WHMCS modules and
 * modules converted from them.
 */
trait CallsWhmcsFunctions
{
    /** The module's name in WHMCS, passed as $params['moduletype']. */
    abstract protected function moduleType(): string;

    /** Whether the module has the WHMCS function, e.g. "ChangePassword". */
    abstract protected function hasFunction(string $function): bool;

    /** Calls the module's function. */
    abstract protected function invoke(string $function, array $params): mixed;

    /** The product's settings as $params entries, including configoption1 to configoption24. @return array<string, string> */
    abstract protected function settings(Product $product): array;

    public function create(Service $service): ProvisioningResult
    {
        return $this->action($service, 'CreateAccount');
    }

    public function suspend(Service $service): ProvisioningResult
    {
        return $this->action($service, 'SuspendAccount');
    }

    public function unsuspend(Service $service): ProvisioningResult
    {
        return $this->action($service, 'UnsuspendAccount');
    }

    public function terminate(Service $service): ProvisioningResult
    {
        return $this->action($service, 'TerminateAccount');
    }

    public function changePackage(Service $service): ProvisioningResult
    {
        return $this->action($service, 'ChangePackage');
    }

    public function changePassword(Service $service): ProvisioningResult
    {
        return $this->action($service, 'ChangePassword');
    }

    public function canChangePassword(Service $service): bool
    {
        return $this->hasFunction('ChangePassword');
    }

    /** The service's server for an action, or null for modules without one. @throws ModuleError */
    protected function server(Service $service): ?Server
    {
        return null;
    }

    /** Called before an action runs, e.g. to give a new service its login. @throws ModuleError */
    protected function prepare(Service $service, ?Server $server): void {}

    /** The port the module is told when the server doesn't set one. */
    protected function defaultPortFor(?Server $server): int
    {
        return 443;
    }

    /** Runs the function for an action on the service. */
    protected function action(Service $service, string $function): ProvisioningResult
    {
        if (! $this->hasFunction($function)) {
            return $function === 'CreateAccount'
                ? ProvisioningResult::failed("The module {$this->moduleType()} has no CreateAccount function.")
                : ProvisioningResult::ok("The module has no {$function} function, so nothing was changed on the server.");
        }

        try {
            $result = $this->call($function, $this->params($service, $this->server($service), true));
        } catch (ModuleError $e) {
            return ProvisioningResult::failed($e->getMessage());
        }

        return self::result($result);
    }

    /** The $params for a function on the service. With $prepare, prepare() runs first. @throws ModuleError */
    protected function params(Service $service, ?Server $server, bool $prepare = false): array
    {
        if ($prepare) {
            $this->prepare($service, $server);
        }

        return Params::forService($this->moduleType(), $service, $server, $this->defaultPortFor($server), $this->settings($service->product));
    }

    /** Calls a function of the module. Errors it throws become a ModuleError. @throws ModuleError */
    protected function call(string $function, array $params): mixed
    {
        $level = ob_get_level();
        ob_start();

        try {
            return $this->invoke($function, $params);
        } catch (ModuleError $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ModuleError("The module failed in {$function}: {$e->getMessage()}", previous: $e);
        } finally {
            // Anything a function prints is dropped, as WHMCS does.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    /** WHMCS modules return "success", or an error message. */
    public static function result(mixed $result): ProvisioningResult
    {
        return match (true) {
            is_string($result) && strtolower(trim($result)) === 'success' => ProvisioningResult::ok(),
            is_string($result) && trim($result) !== '' => ProvisioningResult::failed(trim(strip_tags($result))),
            is_array($result) && is_string($result['error'] ?? null) && $result['error'] !== '' => ProvisioningResult::failed($result['error']),
            default => ProvisioningResult::failed('The module didn\'t return "success".'),
        };
    }
}
