<?php

namespace App\System;

use Illuminate\Support\Facades\File;
use ZipArchive;

/** Unpacks zip files from releases and the marketplace without letting an entry write outside its folder. */
final class Archive
{
    /** The most a zip may unpack to. */
    public const MAX_BYTES = 1024 * 1024 * 1024;

    public static function extract(string $zipPath, string $destination, int $maxBytes = self::MAX_BYTES): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new ArchiveException('That isn\'t a zip file, or it is damaged.');
        }

        try {
            $total = 0;
            $entries = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = self::safeName((string) $stat['name']);
                $total += (int) $stat['size'];

                if ($total > $maxBytes) {
                    throw new ArchiveException('The zip unpacks to more than '.intdiv($maxBytes, 1024 * 1024).' MB.');
                }

                if ($name !== null) {
                    $entries[$i] = $name;
                }
            }

            File::ensureDirectoryExists($destination);

            foreach ($entries as $i => $name) {
                $target = $destination.'/'.$name;

                if (str_ends_with($name, '/')) {
                    File::ensureDirectoryExists($target);

                    continue;
                }

                File::ensureDirectoryExists(dirname($target));
                $in = $zip->getStream($zip->getNameIndex($i));
                $out = fopen($target, 'wb');

                if ($in === false || $out === false) {
                    throw new ArchiveException("Can't unpack {$name}.");
                }

                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * The entry's path with forward slashes, or null for entries to skip (Mac
     * resource forks). Throws for any path that would leave the folder.
     */
    public static function safeName(string $name): ?string
    {
        $name = str_replace('\\', '/', $name);

        if (str_starts_with($name, '__MACOSX/') || $name === '') {
            return null;
        }

        if (str_starts_with($name, '/') || preg_match('/^[a-zA-Z]:/', $name) || str_contains($name, "\0")
            || in_array('..', explode('/', $name), true)) {
            throw new ArchiveException("The zip has an unsafe path in it ({$name}), so it wasn't unpacked.");
        }

        $name = (string) preg_replace('#^(\./)+#', '', (string) preg_replace('#/+#', '/', $name));

        return in_array($name, ['', '.', '/'], true) ? null : $name;
    }
}
