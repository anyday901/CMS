<?php

namespace App\Provisioning\Whmcs;

use App\Models\Product;
use App\Models\Server;
use App\Models\WhmcsModule;
use Illuminate\Database\QueryException;
use PDOException;

/** The uploaded WHMCS server modules: where they are kept, and the enabled ones as provisioning modules. */
class WhmcsModules
{
    /** Module keys of uploaded WHMCS modules start with this. */
    public const PREFIX = 'whmcs_';

    public function path(): string
    {
        return rtrim((string) config('provisioning.whmcs_modules_path'), '/');
    }

    public function folder(string $name): string
    {
        return $this->path().'/'.$name;
    }

    public function file(WhmcsModule $module): string
    {
        return $this->folder($module->name).'/'.$module->name.'.php';
    }

    /** @return list<WhmcsProvisioningModule> the enabled modules */
    public function modules(): array
    {
        try {
            $records = WhmcsModule::where('enabled', true)->orderBy('name')->get();
        } catch (QueryException|PDOException) {
            // Not migrated yet.
            return [];
        }

        return $records->map(fn (WhmcsModule $record) => $this->wrap($record))->all();
    }

    public function wrap(WhmcsModule $record): WhmcsProvisioningModule
    {
        $class = match (true) {
            ! ($record->meta['requires_server'] ?? true) => WhmcsProvisioningModule::class,
            $record->has('ServiceSingleSignOn') => WhmcsSsoServerModule::class,
            default => WhmcsServerModule::class,
        };

        return new $class($record, $this->file($record));
    }

    /** The module key for a WHMCS module name, when that module is uploaded and enabled here. */
    public function keyFor(mixed $whmcsName): ?string
    {
        $name = strtolower(trim((string) $whmcsName));

        if ($name === '') {
            return null;
        }

        try {
            $record = WhmcsModule::where('enabled', true)->get()->first(fn (WhmcsModule $module) => strtolower($module->name) === $name);
        } catch (QueryException|PDOException) {
            return null;
        }

        return $record?->key();
    }

    /** Whether products or servers use the module, so it can't be turned off or removed. */
    public function inUse(WhmcsModule $module): bool
    {
        return Product::where('module', $module->key())->exists() || Server::where('module', $module->key())->exists();
    }
}
