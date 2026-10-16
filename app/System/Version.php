<?php

namespace App\System;

/** The version of CMS running here, from the VERSION file that releases carry. */
final class Version
{
    public static function current(): string
    {
        $file = base_path('VERSION');

        $version = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return $version ?: '0.0.0';
    }

    /** "v1.2.0" -> "1.2.0". */
    public static function clean(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }

    /** A development copy (such as 1.0.0-dev) runs from a git checkout, not a release. */
    public static function isDevelopment(?string $version = null): bool
    {
        return str_contains($version ?? self::current(), '-dev');
    }

    public static function newer(string $candidate, ?string $than = null): bool
    {
        return version_compare(self::clean($candidate), self::clean($than ?? self::current()), '>');
    }

    /** Whether this install meets a package's minimum version. A development copy meets its own version's line. */
    public static function satisfies(?string $minimum): bool
    {
        if (blank($minimum)) {
            return true;
        }

        $current = str_replace('-dev', '', self::current());

        return version_compare($current, self::clean((string) $minimum), '>=');
    }
}
