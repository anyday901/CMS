<?php

namespace App\Marketplace;

/**
 * Add-ons unpacked from the marketplace into extensions/addons/{key}: reads
 * each addon.json and autoloads its classes, so AddonManager can offer it.
 */
final class ExtensionAddons
{
    /** @var array<string, string> PSR-4 prefix => folder */
    private static array $prefixes = [];

    private static bool $registered = false;

    /** @return list<class-string> the add-on classes found, with their autoloading set up */
    public static function classes(): array
    {
        $root = (string) config('marketplace.addons_path');
        $classes = [];

        foreach (glob($root.'/*/addon.json') ?: [] as $file) {
            $manifest = Manifest::read(dirname($file), Manifest::ADDON);

            if ($manifest === null) {
                continue;
            }

            foreach ($manifest['autoload'] as $prefix => $folder) {
                self::$prefixes[$prefix] = dirname($file).'/'.trim($folder, '/');
            }
            $classes[] = $manifest['class'];
        }

        self::register();

        return array_values(array_filter($classes, fn (string $class) => class_exists($class)));
    }

    private static function register(): void
    {
        if (self::$registered || self::$prefixes === []) {
            return;
        }

        self::$registered = true;
        spl_autoload_register(function (string $class) {
            foreach (self::$prefixes as $prefix => $folder) {
                if (str_starts_with($class, $prefix)) {
                    $file = $folder.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
                    if (is_file($file)) {
                        require_once $file;
                    }

                    return;
                }
            }
        });
    }
}
