<?php

namespace App\Marketplace;

/** Reads and checks addon.json and theme.json in a package folder. */
final class Manifest
{
    public const ADDON = 'addon';

    public const THEME = 'theme';

    public const KEY = '/^[a-z0-9][a-z0-9-]{0,49}$/';

    /** Keys a package may not take. */
    public const RESERVED = ['harbor', 'overrides'];

    /**
     * @return array{type: string, key: string, name: string, version: string, requires: string|null, class?: class-string, autoload?: array<string, string>}|null
     */
    public static function read(string $folder, ?string $type = null): ?array
    {
        foreach ($type ? [$type] : [self::ADDON, self::THEME] as $kind) {
            if (is_file($folder.'/'.$kind.'.json')) {
                return self::parse($kind, (string) file_get_contents($folder.'/'.$kind.'.json'));
            }
        }

        return null;
    }

    private static function parse(string $kind, string $json): ?array
    {
        $data = json_decode($json, true);
        if (! is_array($data) || ! is_string($data['key'] ?? null) || ! preg_match(self::KEY, $data['key']) || in_array($data['key'], self::RESERVED, true)) {
            return null;
        }

        $manifest = [
            'type' => $kind,
            'key' => $data['key'],
            'name' => (string) ($data['name'] ?? $data['key']),
            'version' => (string) ($data['version'] ?? ''),
            'requires' => isset($data['requires']) ? (string) $data['requires'] : null,
        ];

        return $kind === self::THEME ? $manifest : self::addon($manifest, $data);
    }

    /** An add-on also names its class, which has to sit under one of the package's own namespaces, and never under the app's. */
    private static function addon(array $manifest, array $data): ?array
    {
        $class = $data['class'] ?? null;
        $autoload = array_filter((array) ($data['autoload']['psr-4'] ?? []), fn ($folder, $prefix) => is_string($folder) && is_string($prefix) && str_ends_with($prefix, '\\') && ! str_contains($folder, '..'), ARRAY_FILTER_USE_BOTH);
        $prefixes = collect(array_keys($autoload));

        $owned = is_string($class) && $prefixes->contains(fn (string $prefix) => str_starts_with($class, $prefix));
        $core = $prefixes->contains(fn (string $prefix) => str_starts_with($prefix, 'App\\') || str_starts_with($prefix, 'Illuminate\\') || str_starts_with($prefix, 'Database\\'));

        return $owned && ! $core ? $manifest + ['class' => $class, 'autoload' => $autoload] : null;
    }
}
