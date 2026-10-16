<?php

namespace App\Marketplace;

use App\Addons\AddonManager;
use App\Models\Activity;
use App\System\Archive;
use App\System\Version;
use App\Themes\ThemeManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Unpacks an add-on or theme package zip into place: add-ons into
 * extensions/addons/{key}, themes into themes/{key}. A newer version
 * replaces the old folder; if that fails, the old one is put back.
 */
class PackageInstaller
{
    /** Package zips can't unpack to more than this. */
    private const MAX_BYTES = 100 * 1024 * 1024;

    public function __construct(private ThemeManager $themes, private AddonManager $addons) {}

    /**
     * @param  array{type?: string, key?: string}  $expected  what the registry said it is
     * @return array{type: string, key: string, name: string, version: string, requires: string|null}
     */
    public function install(string $zip, array $expected = [], string $source = 'an uploaded package'): array
    {
        $staging = storage_path('app/marketplace/'.Str::random(12));

        try {
            Archive::extract($zip, $staging, self::MAX_BYTES);
            $root = $this->root($staging);
            $manifest = Manifest::read($root) ?? throw new PackageException('The package has no valid addon.json or theme.json. See the marketplace guide in the docs.');

            if (isset($expected['type']) && $expected['type'] !== $manifest['type'] || isset($expected['key']) && $expected['key'] !== $manifest['key']) {
                throw new PackageException("The download is {$manifest['type']} \"{$manifest['key']}\", not what the marketplace listed.");
            }

            if (! Version::satisfies($manifest['requires'])) {
                throw new PackageException("{$manifest['name']} needs CMS {$manifest['requires']} or newer; this is ".Version::current().'.');
            }

            $this->move($root, $this->target($manifest['type'], $manifest['key']));
        } finally {
            File::deleteDirectory($staging);
        }

        $this->themes->forget();
        $this->addons->refresh();
        Activity::record("Installed the {$manifest['name']} {$manifest['type']} {$manifest['version']} from {$source}", null, ['key' => $manifest['key']]);

        return $manifest;
    }

    /** Deletes an installed package's folder. The active theme and installed add-ons have to be switched off first. */
    public function remove(string $type, string $key): void
    {
        $folder = $this->target($type, $key);
        $manifest = is_dir($folder) ? Manifest::read($folder, $type) : null;

        if ($manifest === null) {
            throw new PackageException('That package isn\'t installed from the marketplace.');
        }

        if ($type === Manifest::THEME && $this->themes->active()->key === $key) {
            throw new PackageException('That theme is in use. Pick another one under Appearance first.');
        }

        if ($type === Manifest::ADDON && $this->addons->isInstalled($this->addonKey($manifest))) {
            throw new PackageException('Uninstall the add-on under Add-ons first.');
        }

        File::deleteDirectory($folder);
        $this->themes->forget();
        $this->addons->refresh();
        Activity::record("Removed the {$manifest['name']} {$type}", null, ['key' => $key]);
    }

    public function target(string $type, string $key): string
    {
        if (! preg_match(Manifest::KEY, $key) || in_array($key, Manifest::RESERVED, true)) {
            throw new PackageException("{$key} isn't a package name.");
        }

        return match ($type) {
            Manifest::ADDON => rtrim((string) config('marketplace.addons_path'), '/').'/'.$key,
            Manifest::THEME => $this->themes->path().'/'.$key,
            default => throw new PackageException("{$type} isn't a kind of package."),
        };
    }

    /** The folder with the manifest: the zip's top level, or the one folder in it. */
    private function root(string $staging): string
    {
        if (Manifest::read($staging) !== null || is_file($staging.'/addon.json') || is_file($staging.'/theme.json')) {
            return $staging;
        }

        $folders = File::directories($staging);

        return count($folders) === 1 ? $folders[0] : $staging;
    }

    /** Puts the new folder in place, keeping the old one until the move works. */
    private function move(string $from, string $to): void
    {
        File::ensureDirectoryExists(dirname($to));
        $old = is_dir($to) ? $to.'.old-'.Str::random(6) : null;

        if ($old !== null) {
            rename($to, $old);
        }

        try {
            if (! @rename($from, $to)) {
                File::copyDirectory($from, $to) || throw new PackageException("Can't write {$to}.");
            }
        } catch (Throwable $e) {
            File::deleteDirectory($to);
            if ($old !== null) {
                rename($old, $to);
            }

            throw $e;
        }

        if ($old !== null) {
            File::deleteDirectory($old);
        }
    }

    /** The key the add-on's class says it has, which is what Settings > Add-ons goes by. */
    private function addonKey(array $manifest): string
    {
        return $this->addons->available()->first(fn ($addon) => $addon::class === $manifest['class'])?->key() ?? $manifest['key'];
    }
}
