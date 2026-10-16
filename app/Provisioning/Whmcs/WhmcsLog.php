<?php

namespace App\Provisioning\Whmcs;

use App\Models\Activity;
use Illuminate\Support\Facades\Log;

/** WHMCS's logModuleCall and logActivity, for uploaded and converted WHMCS modules. */
final class WhmcsLog
{
    /** Writes the module call to the app log at debug level, with the $replace values masked. */
    public static function moduleCall(mixed $module, mixed $action, mixed $request, mixed $response, mixed $processed = '', mixed $replace = []): void
    {
        $secrets = array_filter(array_map(fn ($value) => is_scalar($value) ? (string) $value : '', (array) $replace));
        $mask = fn (mixed $value) => str_replace($secrets, '***', is_scalar($value) ? (string) $value : (string) json_encode($value));

        Log::debug("WHMCS module {$module}: {$action}", ['request' => $mask($request), 'response' => $mask($response), 'processed' => $mask($processed)]);
    }

    public static function activity(mixed $message): void
    {
        Activity::record('WHMCS module: '.$message);
    }
}
