<?php

/*
 * The parts of WHMCS that uploaded WHMCS modules commonly call, loaded once
 * before the first module. Anything else a module calls from WHMCS fails as
 * an error, which staff see on the service.
 */

namespace WHMCS\Database {
    use Illuminate\Database\Connection;
    use Illuminate\Database\Schema\Builder;
    use Illuminate\Support\Facades\DB;

    /**
     * WHMCS's database access. Here it reaches this app's database: a module's
     * own tables (mod_...) work, WHMCS's (tblhosting and so on) don't exist.
     */
    class Capsule
    {
        public static function connection(): Connection
        {
            return DB::connection();
        }

        public static function schema(): Builder
        {
            return DB::connection()->getSchemaBuilder();
        }

        public static function __callStatic(string $method, array $arguments): mixed
        {
            return DB::connection()->{$method}(...$arguments);
        }
    }
}

namespace {
    use App\Provisioning\Whmcs\WhmcsLog;

    if (! defined('WHMCS')) {
        define('WHMCS', true);
    }

    /** WHMCS's module log: written to the app log, with $replace values masked. */
    function logModuleCall($module, $action, $request, $response, $processed = '', $replace = []): void
    {
        WhmcsLog::moduleCall($module, $action, $request, $response, $processed, $replace);
    }

    function logActivity($message, $userId = 0): void // NOSONAR: WHMCS's signature
    {
        WhmcsLog::activity($message);
    }

    /** WHMCS's internal API isn't here; modules get the error WHMCS gives for an unknown command. */
    function localAPI($command, $values = [], $adminUser = ''): array // NOSONAR: WHMCS's signature
    {
        return ['result' => 'error', 'message' => "The WHMCS API command {$command} isn't available in CMS."];
    }
}
