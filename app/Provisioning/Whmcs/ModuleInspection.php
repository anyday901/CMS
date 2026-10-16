<?php

namespace App\Provisioning\Whmcs;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Loads a WHMCS module in a separate PHP process (whmcs:inspect-module) and
 * returns what it says about itself: its name, whether it uses servers, its
 * default ports and its product settings.
 */
final class ModuleInspection
{
    private const TIMEOUT = 60;

    /**
     * @return array{display_name: string, requires_server: bool, ports: array{secure: int, plain: int}, config_fields: array<string, array<string, mixed>>}
     *
     * @throws ModuleUploadException when the module fails to load
     */
    public static function run(string $file): array
    {
        try {
            $result = Process::path(base_path())->timeout(self::TIMEOUT)
                ->run([(new PhpExecutableFinder)->find(false) ?: 'php', 'artisan', 'whmcs:inspect-module', $file]);
        } catch (ProcessTimedOutException $e) {
            throw new ModuleUploadException('The module took more than '.self::TIMEOUT.' seconds to load, so it was stopped.', previous: $e);
        }

        $output = trim($result->output());
        $json = json_decode((string) Str::afterLast("\n".$output, "\n"), true);

        if ($result->successful() && is_array($json) && isset($json['display_name'])) {
            return $json;
        }

        $error = is_array($json) && is_string($json['error'] ?? null)
            ? $json['error']
            : (Str::limit(trim(strip_tags($result->errorOutput() ?: $output)), 500) ?: 'PHP stopped without saying why');

        throw new ModuleUploadException("The module failed when it was loaded: {$error}");
    }
}
