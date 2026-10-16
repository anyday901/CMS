<?php

namespace App\Provisioning\Whmcs;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Loads WHMCS modules and converted modules in a separate PHP process, so
 * one that stops PHP when it loads can't take the admin page down: reads
 * what a WHMCS module says about itself (whmcs:inspect-module), and checks
 * a converted module's class loads (whmcs:check-converted).
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
        return self::artisan(['whmcs:inspect-module', $file], 'display_name');
    }

    /**
     * Loads a converted module's class in a separate process and checks it is one.
     *
     * @throws ModuleUploadException when it doesn't load
     */
    public static function check(string $file, string $class): void
    {
        self::artisan(['whmcs:check-converted', $file, $class], 'key');
    }

    /** Runs the artisan command and returns its JSON answer, which must have $expect. @param  list<string>  $command */
    private static function artisan(array $command, string $expect): array
    {
        try {
            $result = Process::path(base_path())->timeout(self::TIMEOUT)
                ->run([(new PhpExecutableFinder)->find(false) ?: 'php', 'artisan', ...$command]);
        } catch (ProcessTimedOutException $e) {
            throw new ModuleUploadException('The module took more than '.self::TIMEOUT.' seconds to load, so it was stopped.', previous: $e);
        }

        $output = trim($result->output());
        $json = json_decode((string) Str::afterLast("\n".$output, "\n"), true);

        if ($result->successful() && is_array($json) && isset($json[$expect])) {
            return $json;
        }

        $error = is_array($json) && is_string($json['error'] ?? null)
            ? $json['error']
            : (Str::limit(trim(strip_tags($result->errorOutput() ?: $output)), 500) ?: 'PHP stopped without saying why');

        throw new ModuleUploadException("The module failed when it was loaded: {$error}");
    }
}
