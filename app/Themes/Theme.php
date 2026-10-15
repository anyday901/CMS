<?php

namespace App\Themes;

/** A portal theme: the built-in Harbor, or a folder under themes/ with a theme.json. */
final class Theme
{
    /**
     * @param  array{brand?: string, ink?: string}  $colors
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $description = '',
        public readonly string $version = '',
        public readonly string $author = '',
        public readonly array $colors = [],
        public readonly ?string $stylesheet = null,
        public readonly ?string $path = null,
        public readonly ?string $requires = null,
    ) {}

    public const KEY = '/^[a-z0-9][a-z0-9-]{0,49}$/';

    /** Reads themes/{key}/theme.json; null when it is missing or broken. */
    public static function fromFolder(string $path): ?self
    {
        $file = $path.'/theme.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $key = basename($path);

        if (! is_array($data) || ! preg_match(self::KEY, $key) || ($data['key'] ?? $key) !== $key) {
            return null;
        }

        $colors = array_filter(
            array_intersect_key((array) ($data['colors'] ?? []), ['brand' => true, 'ink' => true]),
            fn ($color) => is_string($color) && self::isColor($color),
        );
        $stylesheet = isset($data['stylesheet']) && is_string($data['stylesheet']) && is_file($path.'/assets/'.$data['stylesheet']) ? $data['stylesheet'] : null;

        return new self(
            key: $key,
            name: (string) ($data['name'] ?? $key),
            description: (string) ($data['description'] ?? ''),
            version: (string) ($data['version'] ?? ''),
            author: (string) ($data['author'] ?? ''),
            colors: $colors,
            stylesheet: $stylesheet,
            path: $path,
            requires: isset($data['requires']) ? (string) $data['requires'] : null,
        );
    }

    public static function isColor(string $color): bool
    {
        return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $color);
    }

    public function builtIn(): bool
    {
        return $this->path === null;
    }

    public function viewsPath(): ?string
    {
        return $this->path !== null && is_dir($this->path.'/views') ? $this->path.'/views' : null;
    }

    public function screenshot(): ?string
    {
        foreach (['screenshot.png', 'screenshot.jpg', 'screenshot.webp'] as $file) {
            if ($this->path !== null && is_file($this->path.'/assets/'.$file)) {
                return $file;
            }
        }

        return null;
    }
}
