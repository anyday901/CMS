<?php

namespace App\Provisioning\Whmcs;

use Illuminate\Support\Facades\File;
use ParseError;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Reads an uploaded WHMCS module's code without running it, and reports
 * what will work here: the functions this app calls, the ones it doesn't,
 * things in the code that won't work, and anything that stops it being
 * installed at all.
 */
final class ModuleInspector
{
    /** The functions this app calls, by when they are used. */
    public const SUPPORTED = [
        'MetaData', 'ConfigOptions', 'CreateAccount', 'SuspendAccount', 'UnsuspendAccount', 'TerminateAccount',
        'ChangePassword', 'ChangePackage', 'TestConnection', 'ServiceSingleSignOn',
    ];

    /** Function endings that make it another kind of WHMCS module. */
    private const KINDS = [
        'registrar' => ['registerdomain', 'transferdomain', 'getnameservers', 'getconfigarray'],
        'add-on' => ['activate', 'deactivate', 'output'],
        'payment gateway' => ['link', 'capture', 'refund', 'nolocalcc', 'storeremote'],
    ];

    /** Legacy WHMCS database functions, gone from WHMCS 8 and not here either. */
    private const LEGACY_DB = ['select_query', 'full_query', 'insert_query', 'update_query', 'delete_query', 'mysql_query', 'mysql_fetch_array', 'mysql_fetch_assoc'];

    /**
     * @return array{kind: string, functions: list<string>, unsupported: list<string>, warnings: list<string>, blockers: list<string>}
     */
    public static function inspect(string $folder, string $name): array
    {
        $main = "{$folder}/{$name}.php";
        $report = ['kind' => 'unknown', 'functions' => [], 'unsupported' => [], 'warnings' => [], 'blockers' => []];
        $sources = [];

        foreach (File::allFiles($folder) as $file) {
            /** @var SplFileInfo $file */
            if (strtolower($file->getExtension()) === 'php') {
                $sources[$file->getRelativePathname()] = (string) file_get_contents($file->getPathname());
            }
        }

        $encoded = array_keys(array_filter($sources, self::encoded(...)));

        if ($encoded !== []) {
            $report['blockers'][] = 'It is encoded (with ionCube or similar), so its code can\'t be read or checked: '.self::few($encoded).'. Ask its developer for an unencoded copy.';

            return $report;
        }

        $functions = [];

        foreach ($sources as $path => $source) {
            try {
                $found = self::functions($source);
            } catch (ParseError $e) {
                $report['blockers'][] = "{$path} has a PHP error on line {$e->getLine()}: {$e->getMessage()}.";

                continue;
            }

            if ($path === "{$name}.php") {
                $functions = $found;
            }
        }

        if (! is_file($main)) {
            $report['blockers'][] = "There is no {$name}.php in the module.";

            return $report;
        }

        $prefix = strtolower($name).'_';
        $endings = [];

        foreach ($functions as $function) {
            if (str_starts_with(strtolower($function), $prefix)) {
                $endings[] = substr($function, strlen($prefix));
            }
        }

        $report['kind'] = self::kind($endings);

        if ($report['kind'] !== 'server') {
            $report['blockers'][] = $report['kind'] === 'unknown'
                ? "{$name}.php has no {$name}_CreateAccount function, so it isn't a WHMCS server module."
                : "It is a WHMCS {$report['kind']} module. Only server (provisioning) modules can be uploaded so far.";

            return $report;
        }

        $supported = array_map('strtolower', self::SUPPORTED);

        foreach ($endings as $ending) {
            $known = array_search(strtolower($ending), $supported, true);
            $known === false ? $report['unsupported'][] = $ending : $report['functions'][] = self::SUPPORTED[$known];
        }

        $report['warnings'] = self::warnings($folder, $sources);

        return $report;
    }

    /** The names of functions declared in the code. @throws ParseError */
    private static function functions(string $source): array
    {
        $tokens = token_get_all($source, TOKEN_PARSE);
        $names = [];
        $depth = 0;
        $classDepth = [];

        foreach ($tokens as $i => $token) {
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
                if ($classDepth !== [] && end($classDepth) === $depth) {
                    array_pop($classDepth);
                }
            } elseif (is_array($token) && in_array($token[0], [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true) && ! self::afterDoubleColon($tokens, $i)) {
                $classDepth[] = $depth;
            } elseif (is_array($token) && $token[0] === T_FUNCTION && $classDepth === []) {
                $next = self::nextToken($tokens, $i);
                if (is_array($next) && $next[0] === T_STRING) {
                    $names[] = $next[1];
                }
            }
        }

        return $names;
    }

    /** Whether the token follows "::", as in Foo::class. */
    private static function afterDoubleColon(array $tokens, int $i): bool
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (! is_array($tokens[$j]) || $tokens[$j][0] !== T_WHITESPACE) {
                return is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_COLON;
            }
        }

        return false;
    }

    private static function nextToken(array $tokens, int $i): mixed
    {
        for ($j = $i + 1; $j < count($tokens); $j++) {
            if (! is_array($tokens[$j]) || ! in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $tokens[$j] === '&' ? self::nextToken($tokens, $j) : $tokens[$j];
            }
        }

        return null;
    }

    /** @param  list<string>  $endings */
    private static function kind(array $endings): string
    {
        $endings = array_map('strtolower', $endings);

        if (in_array('createaccount', $endings, true)) {
            return 'server';
        }

        foreach (self::KINDS as $kind => $markers) {
            if (array_intersect($markers, $endings) !== [] && ($kind === 'registrar' || in_array('config', $endings, true))) {
                return $kind;
            }
        }

        return 'unknown';
    }

    private static function encoded(string $source): bool
    {
        return (bool) preg_match('/ionCube Loader|sg_load\s*\(|SourceGuardian|zend_loader_file_encoded|<\?php\s+\/\/0[0-9a-f]{2}/i', $source);
    }

    /** @param  array<string, string>  $sources @return list<string> */
    private static function warnings(string $folder, array $sources): array
    {
        $code = implode("\n", $sources);
        $warnings = [];

        preg_match_all('/[\'"`](tbl[a-z_]+)[\'"`]/i', $code, $tables);
        if ($tables[1] !== []) {
            $warnings[] = 'It reads WHMCS\'s own database tables ('.self::few(array_unique($tables[1])).'), which don\'t exist here, so those parts will fail.';
        }

        if (preg_match('/\blocalAPI\s*\(/i', $code)) {
            $warnings[] = 'It calls the WHMCS internal API (localAPI), which isn\'t available here and always returns an error.';
        }

        $legacy = array_values(array_filter(self::LEGACY_DB, fn ($function) => preg_match('/\b'.$function.'\s*\(/i', $code)));
        if ($legacy !== []) {
            $warnings[] = 'It uses old WHMCS database functions ('.self::few($legacy).') that aren\'t available, so those parts will fail.';
        }

        if (preg_match('/(?<![\w>:$])(?:en|de)crypt\s*\(/i', $code)) {
            $warnings[] = 'It calls encrypt() or decrypt(). Here those are Laravel\'s, which can\'t read values WHMCS encrypted.';
        }

        if (preg_match('/\bROOTDIR\b/', $code)) {
            $warnings[] = 'It loads WHMCS files through ROOTDIR, which isn\'t here.';
        }

        preg_match_all('/\\\\?WHMCS\\\\([A-Za-z\\\\]+)/', $code, $classes);
        $classes = array_values(array_diff(array_unique($classes[1]), ['Database\\Capsule']));
        if ($classes !== []) {
            $warnings[] = 'It uses WHMCS classes ('.self::few($classes).') that don\'t exist here. Only WHMCS\\Database\\Capsule is provided.';
        }

        if (is_file($folder.'/hooks.php') || preg_match('/\badd_hook\s*\(/i', $code)) {
            $warnings[] = 'Its WHMCS hooks (hooks.php, add_hook) aren\'t loaded.';
        }

        if (File::glob($folder.'/{,*/}*.tpl', GLOB_BRACE) !== []) {
            $warnings[] = 'Its client area templates (.tpl) aren\'t shown. Clients see the usual service page.';
        }

        return $warnings;
    }

    /** @param  array<string>  $items */
    private static function few(array $items): string
    {
        $items = array_values($items);

        return implode(', ', array_slice($items, 0, 5)).(count($items) > 5 ? ' and '.(count($items) - 5).' more' : '');
    }
}
