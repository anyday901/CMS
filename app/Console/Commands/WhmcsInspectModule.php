<?php

namespace App\Console\Commands;

use App\Provisioning\Whmcs\ConfigFields;
use App\Provisioning\Whmcs\ModuleLoader;
use Illuminate\Console\Command;
use Throwable;

/**
 * Loads a WHMCS module and prints what it says about itself as JSON. Run in
 * its own process when staff enable a module, so a module that stops PHP
 * when it loads can't take the admin page down with it.
 */
class WhmcsInspectModule extends Command
{
    protected $signature = 'whmcs:inspect-module {file : The module\'s main PHP file}';

    protected $description = 'Load an uploaded WHMCS module and print its MetaData and ConfigOptions as JSON';

    protected $hidden = true;

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        $name = basename($file, '.php');

        try {
            ModuleLoader::load($file);
            $meta = ModuleLoader::has($name, 'MetaData') ? ModuleLoader::call($name, 'MetaData') : [];
            $meta = is_array($meta) ? $meta : [];
            $options = ModuleLoader::has($name, 'ConfigOptions') ? ModuleLoader::call($name, 'ConfigOptions') : [];

            $this->line((string) json_encode([
                'display_name' => is_string($meta['DisplayName'] ?? null) && trim($meta['DisplayName']) !== '' ? trim(strip_tags($meta['DisplayName'])) : $name,
                'requires_server' => ! array_key_exists('RequiresServer', $meta) || (bool) $meta['RequiresServer'],
                'ports' => ['secure' => (int) ($meta['DefaultSSLPort'] ?? 0) ?: 443, 'plain' => (int) ($meta['DefaultNonSSLPort'] ?? 0) ?: 80],
                'config_fields' => ConfigFields::from($options),
            ]));
        } catch (Throwable $e) {
            $this->line((string) json_encode(['error' => $e->getMessage().' ('.basename($e->getFile()).' line '.$e->getLine().')']));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
