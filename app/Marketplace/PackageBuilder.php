<?php

namespace App\Marketplace;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/** Zips a package folder for the marketplace, and builds the registry from marketplace/. */
class PackageBuilder
{
    /** Left out of package zips. */
    private const SKIP = ['.git', '.github', 'node_modules', '.DS_Store', 'tests'];

    /** Zips the folder (its manifest at the top level) and returns the zip's SHA-256. */
    public function zip(string $folder, string $output): string
    {
        $folder = rtrim($folder, '/');

        if (Manifest::read($folder) === null) {
            throw new RuntimeException("{$folder} has no valid addon.json or theme.json.");
        }

        @mkdir(dirname($output), 0755, true);
        @unlink($output);
        $zip = new ZipArchive;

        if ($zip->open($output, ZipArchive::CREATE) !== true) {
            throw new RuntimeException("Can't write {$output}.");
        }

        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $relative = ltrim(substr($file->getPathname(), strlen($folder)), '/');
            if ($file->isFile() && array_intersect(explode('/', $relative), self::SKIP) === []) {
                $files[] = $relative;
            }
        }

        // Same files in the same order, with fixed dates, give the same zip and checksum every time.
        sort($files);
        foreach ($files as $relative) {
            $zip->addFile($folder.'/'.$relative, $relative);
            $zip->setMtimeName($relative, 315532800);
        }

        $zip->close();

        return hash_file('sha256', $output);
    }

    /**
     * Builds every package listed in {source}/registry.json from
     * {source}/packages/{key}, and writes {output}/registry.json with
     * download links under the base URL.
     *
     * @return list<array<string, mixed>> the packages written
     */
    public function registry(string $source, string $output, string $baseUrl): array
    {
        $listing = json_decode((string) @file_get_contents($source.'/registry.json'), true);

        if (! is_array($listing) || ! is_array($listing['packages'] ?? null)) {
            throw new RuntimeException("{$source}/registry.json has no packages list.");
        }

        $packages = [];

        foreach ($listing['packages'] as $entry) {
            $folder = $source.'/packages/'.($entry['key'] ?? '');
            $manifest = Manifest::read($folder);

            if ($manifest === null || $manifest['key'] !== ($entry['key'] ?? null) || $manifest['type'] !== ($entry['type'] ?? null)) {
                throw new RuntimeException('marketplace/packages/'.($entry['key'] ?? '?').' is missing, or its manifest doesn\'t match registry.json.');
            }

            $name = "{$manifest['key']}-{$manifest['version']}.zip";
            $sha256 = $this->zip($folder, $output.'/packages/'.$name);

            $packages[] = array_merge($entry, [
                'name' => $entry['name'] ?? $manifest['name'],
                'version' => $manifest['version'],
                'requires' => $manifest['requires'],
                'download_url' => rtrim($baseUrl, '/').'/packages/'.$name,
                'sha256' => $sha256,
            ]);
        }

        file_put_contents($output.'/registry.json', json_encode(['generated_at' => now()->toIso8601String(), 'packages' => $packages], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return $packages;
    }
}
