<?php

namespace App\Provisioning;

use Illuminate\Support\Collection;

class ModuleRegistry
{
    /** @return Collection<string, ProvisioningModule> keyed by module key */
    public function all(): Collection
    {
        return collect(config('provisioning.modules', []))
            ->map(fn (string $class) => app($class))
            ->keyBy(fn (ProvisioningModule $module) => $module->key());
    }

    public function find(?string $key): ?ProvisioningModule
    {
        return $key === null ? null : $this->all()->get($key);
    }

    /** @return Collection<string, ProvisioningModule&UsesServers> modules that set services up on servers */
    public function withServers(): Collection
    {
        return $this->all()->filter(fn (ProvisioningModule $module) => $module instanceof UsesServers);
    }

    /** Whether services of the module are set up for a domain name, which the order then asks for. */
    public function needsDomain(?string $key): bool
    {
        $module = $this->find($key);

        return $module instanceof UsesServers && $module->needsDomain();
    }
}
