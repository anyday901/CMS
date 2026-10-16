<?php

namespace App\Provisioning\Converted;

use App\Models\Product;
use App\Provisioning\ChangesPackage;
use App\Provisioning\ChangesPassword;
use App\Provisioning\ProvisioningModule;
use App\Provisioning\Whmcs\CallsWhmcsFunctions;
use App\Provisioning\Whmcs\Params;
use App\Provisioning\Whmcs\WhmcsLog;

/**
 * A module converted from a WHMCS module under Settings > WHMCS modules: a
 * class whose methods are the WHMCS module's functions. Each action calls
 * its method (createAccount, suspendAccount and so on, see METHODS) with
 * the $params WHMCS would pass, so the converted code works as it did; the
 * product's settings arrive by name as well as configoption1 to 24.
 */
abstract class ConvertedModule implements ChangesPackage, ChangesPassword, ProvisioningModule
{
    use CallsWhmcsFunctions;

    /** The method each WHMCS function became. */
    public const METHODS = [
        'CreateAccount' => 'createAccount',
        'SuspendAccount' => 'suspendAccount',
        'UnsuspendAccount' => 'unsuspendAccount',
        'TerminateAccount' => 'terminateAccount',
        'ChangePassword' => 'changeAccountPassword',
        'ChangePackage' => 'changeAccountPackage',
        'TestConnection' => 'checkConnection',
        'ServiceSingleSignOn' => 'singleSignOn',
    ];

    /** The module's name in WHMCS, sent as $params['moduletype']. */
    abstract public function whmcsName(): string;

    /**
     * The setting each WHMCS config option became, by its position.
     *
     * @return array<int, string> e.g. [1 => 'plan']
     */
    abstract public function settingPositions(): array;

    protected function moduleType(): string
    {
        return $this->whmcsName();
    }

    protected function hasFunction(string $function): bool
    {
        return isset(self::METHODS[$function]) && method_exists($this, self::METHODS[$function]);
    }

    protected function invoke(string $function, array $params): mixed
    {
        return $this->{self::METHODS[$function]}($params);
    }

    protected function settings(Product $product): array
    {
        $config = $product->module_config ?? [];
        $named = [];

        foreach ($this->settingPositions() as $name) {
            $named[$name] = (string) ($config[$name] ?? '');
        }

        return [...$named, ...Params::configOptions($config, $this->settingPositions())];
    }

    protected function logModuleCall(mixed $module, mixed $action, mixed $request, mixed $response, mixed $processed = '', mixed $replace = []): void
    {
        WhmcsLog::moduleCall($module, $action, $request, $response, $processed, $replace);
    }

    protected function logActivity(mixed $message, mixed $userId = 0): void
    {
        WhmcsLog::activity($message);
    }
}
