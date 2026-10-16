<?php

namespace App\Console\Commands;

use App\Provisioning\Converted\ConvertedModule;
use App\Provisioning\Whmcs\ModuleUploadException;
use Illuminate\Console\Command;
use Throwable;

/** Loads a module converted from WHMCS and prints its key as JSON. Run in its own process before the module is put in place. */
class WhmcsCheckConverted extends Command
{
    protected $signature = 'whmcs:check-converted {file : The converted class file} {class : The class name}';

    protected $description = 'Load a module converted from WHMCS and print its key and settings as JSON';

    protected $hidden = true;

    public function handle(): int
    {
        $class = (string) $this->argument('class');

        try {
            require_once (string) $this->argument('file');
            $module = new $class;

            if (! $module instanceof ConvertedModule) {
                throw new ModuleUploadException("{$class} isn't a converted module.");
            }

            $this->line((string) json_encode(['key' => $module->key(), 'label' => $module->label(), 'settings' => array_keys($module->configFields())]));
        } catch (Throwable $e) {
            $this->line((string) json_encode(['error' => $e->getMessage().' ('.basename($e->getFile()).' line '.$e->getLine().')']));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
