<?php

namespace App\Provisioning\Whmcs;

use App\Models\Product;
use App\Models\WhmcsModule;
use App\Provisioning\ChangesPackage;
use App\Provisioning\ChangesPassword;
use App\Provisioning\ProvisioningModule;

/**
 * An uploaded WHMCS server module, run as one of this app's provisioning
 * modules (see CallsWhmcsFunctions). This one is for modules that don't use
 * a server (MetaData's RequiresServer is false); WhmcsServerModule is for
 * those that do.
 */
class WhmcsProvisioningModule implements ChangesPackage, ChangesPassword, ProvisioningModule
{
    use CallsWhmcsFunctions;

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

    protected function moduleType(): string
    {
        return $this->record->name;
    }

    protected function hasFunction(string $function): bool
    {
        return $this->record->has($function);
    }

    protected function invoke(string $function, array $params): mixed
    {
        ModuleLoader::load($this->file);

        return ModuleLoader::call($this->record->name, $function, $params);
    }

    protected function settings(Product $product): array
    {
        return Params::configOptions($product->module_config ?? []);
    }
}
