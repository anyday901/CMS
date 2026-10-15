<?php

namespace App\System;

use RuntimeException;

/** Reads and changes the .env file, for the web installer. */
class EnvFile
{
    public function __construct(private ?string $path = null)
    {
        $this->path ??= base_path('.env');
    }

    public function path(): string
    {
        return $this->path;
    }

    public function writable(): bool
    {
        return is_file($this->path) ? is_writable($this->path) : is_writable(dirname($this->path));
    }

    /** @param  array<string, string|int|bool|null>  $values */
    public function set(array $values): void
    {
        if (! is_file($this->path)) {
            $example = dirname($this->path).'/.env.example';
            is_file($example) ? copy($example, $this->path) : touch($this->path);
        }

        $contents = (string) file_get_contents($this->path);

        foreach ($values as $key => $value) {
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                throw new RuntimeException("{$key} isn't a setting name.");
            }

            $line = $key.'='.self::format($value);
            // Replace the setting, or a commented-out one such as "# DB_HOST=", or add it at the end.
            $pattern = '/^#?\s*'.preg_quote($key, '/').'=.*$/m';
            $contents = preg_match($pattern, $contents)
                ? preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $contents, 1)
                : rtrim($contents)."\n".$line."\n";
        }

        if (file_put_contents($this->path, $contents) === false) {
            throw new RuntimeException("Can't write {$this->path}.");
        }
    }

    /** A value as .env writes it: quoted when it has spaces or characters the parser treats specially. */
    public static function format(string|int|bool|null $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            $value === '' => '',
            (bool) preg_match('/^[A-Za-z0-9_.:\/@+-]+$/', $value) => $value,
            default => '"'.str_replace(['\\', '"', '$', "\n"], ['\\\\', '\\"', '\\$', '\\n'], $value).'"',
        };
    }
}
