<?php

namespace App\Provisioning\Whmcs;

use App\Provisioning\ModuleError;

/**
 * Loads a WHMCS module's file, with the WHMCS functions it expects, and
 * calls its functions: {name}_{Function}($params).
 */
final class ModuleLoader
{
    public static function load(string $file): void
    {
        if (! is_file($file)) {
            throw new ModuleError('The WHMCS module\'s files are missing. Upload it again under Settings > WHMCS modules.');
        }

        require_once __DIR__.'/compat.php';
        (static function (string $file) {
            require_once $file;
        })($file);
    }

    /** Whether the loaded module has the function, e.g. "CreateAccount". */
    public static function has(string $name, string $function): bool
    {
        return function_exists("{$name}_{$function}");
    }

    /** Calls the module's function. Anything it prints is dropped, as WHMCS does for these calls. */
    public static function call(string $name, string $function, array $params = []): mixed
    {
        $level = ob_get_level();
        ob_start();

        try {
            return ("{$name}_{$function}")($params);
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }
}
