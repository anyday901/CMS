<?php

namespace App\Provisioning\Whmcs;

use App\Models\Server;
use App\Models\Service;
use App\Models\WhmcsModule;
use App\Provisioning\ChangesPackage;
use App\Provisioning\ChangesPassword;
use App\Provisioning\ModuleError;
use App\Provisioning\ProvisioningModule;
use App\Provisioning\ProvisioningResult;
use Throwable;

/**
 * An uploaded WHMCS server module, run as one of this app's provisioning
 * modules: each action calls the module's matching WHMCS function with the
 * $params WHMCS would pass, and its "success" or error message becomes the
 * result. This one is for modules that don't use a server (MetaData's
 * RequiresServer is false); WhmcsServerModule is for those that do.
 */
class WhmcsProvisioningModule implements ChangesPackage, ChangesPassword, ProvisioningModule
{
    public function __construct(public readonly WhmcsModule $record, protected string $file) {}

    public function key(): string
    {
        return $this->record->key();
    }

    public function label(): string
    {
        return $this->record->display_name.' (WHMCS)';
    }

    public function configFields(): array
    {
        return $this->record->meta['config_fields'] ?? [];
    }

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
        return $this->record->has('ChangePassword');
    }

    /** The service's server for an action, or null for modules without one. @throws ModuleError */
    protected function server(Service $service): ?Server
    {
        return null;
    }

    /** The port the module is told when the server doesn't set one. */
    protected function defaultPortFor(?Server $server): int
    {
        return 443;
    }

    /** Runs the WHMCS function for an action on the service. */
    protected function action(Service $service, string $function): ProvisioningResult
    {
        if (! $this->record->has($function)) {
            return $function === 'CreateAccount'
                ? ProvisioningResult::failed("The WHMCS module {$this->record->name} has no CreateAccount function.")
                : ProvisioningResult::ok("The WHMCS module has no {$function} function, so nothing was changed on the server.");
        }

        try {
            $server = $this->server($service);
            $this->prepare($service, $server);
            $result = $this->call($function, Params::forService($this->record, $service->refresh(), $server, $this->defaultPortFor($server)));
        } catch (ModuleError $e) {
            return ProvisioningResult::failed($e->getMessage());
        }

        return self::result($result);
    }

    /** Called before an action runs, e.g. to give a new service its login. @throws ModuleError */
    protected function prepare(Service $service, ?Server $server): void {}

    /** Calls a function of the module. Errors it throws become a ModuleError. @throws ModuleError */
    protected function call(string $function, array $params): mixed
    {
        try {
            ModuleLoader::load($this->file);

            return ModuleLoader::call($this->record->name, $function, $params);
        } catch (ModuleError $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ModuleError("The WHMCS module failed in {$function}: {$e->getMessage()}", previous: $e);
        }
    }

    /** WHMCS modules return "success", or an error message. */
    public static function result(mixed $result): ProvisioningResult
    {
        return match (true) {
            is_string($result) && strtolower(trim($result)) === 'success' => ProvisioningResult::ok(),
            is_string($result) && trim($result) !== '' => ProvisioningResult::failed(trim(strip_tags($result))),
            is_array($result) && is_string($result['error'] ?? null) && $result['error'] !== '' => ProvisioningResult::failed($result['error']),
            default => ProvisioningResult::failed('The WHMCS module didn\'t return "success".'),
        };
    }
}
