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
}
