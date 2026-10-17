<?php

namespace App\Provisioning\Converted;

use App\Models\Product;
use App\Models\Server;
use Throwable;

/**
 * Modules converted from WHMCS modules, each in its own folder under
 * extensions/modules with a module.json naming its class file:
 *
 *     {"key": "mypanel", "class": "MypanelModule", "file": "MypanelModule.php",
 *      "whmcs_name": "mypanel", "settings": {"configoption1": "plan"}}
 *
 * A folder can be copied into another install's extensions/modules as it is.
 */
class ConvertedModules
{
    public function path(): string
    {
        return rtrim((string) config('provisioning.converted_modules_path'), '/');
    }

    public function folder(string $key): string
    {
        return $this->path().'/'.$key;
    }

    /** @return list<array{key: string, class: string, file: string, whmcs_name: string, settings: array<string, string>, converted_at?: string, folder: string}> */
    public function manifests(): array
    {
        $manifests = [];

        foreach (glob($this->path().'/*/module.json') ?: [] as $file) {
            $manifest = json_decode((string) file_get_contents($file), true);

            if (is_array($manifest) && is_string($manifest['key'] ?? null) && is_string($manifest['class'] ?? null) && is_string($manifest['file'] ?? null)
                && basename($manifest['file']) === $manifest['file']) {
                $manifests[] = [...$manifest, 'whmcs_name' => (string) ($manifest['whmcs_name'] ?? ''), 'settings' => (array) ($manifest['settings'] ?? []), 'folder' => dirname($file)];
            }
        }

        return $manifests;
    }

    /** @return list<ConvertedModule> */
    public function modules(): array
    {
        $modules = [];

        foreach ($this->manifests() as $manifest) {
            try {
                require_once $manifest['folder'].'/'.$manifest['file'];
                $module = new $manifest['class'];
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if ($module instanceof ConvertedModule) {
                $modules[] = $module;
            }
        }

        return $modules;
    }

    /** The converted module for a WHMCS module name, if there is one. @return array<string, mixed>|null */
    public function forWhmcsName(mixed $whmcsName): ?array
    {
        $name = strtolower(trim((string) $whmcsName));

        return $name === '' ? null : collect($this->manifests())->first(fn (array $manifest) => strtolower($manifest['whmcs_name']) === $name);
    }

    /** @return array<string, mixed>|null */
    public function find(string $key): ?array
    {
        return collect($this->manifests())->firstWhere('key', $key);
    }

    public function inUse(string $key): bool
    {
        return Product::where('module', $key)->exists() || Server::where('module', $key)->exists();
    }
}
